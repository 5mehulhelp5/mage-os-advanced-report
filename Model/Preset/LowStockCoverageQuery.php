<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Preset;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Api\PresetQueryInterface;
use MageOS\AdvancedReport\Api\ReportRunnerInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\DataSourcePool;
use MageOS\AdvancedReport\Model\Query\DimensionSelection;
use MageOS\AdvancedReport\Model\Query\NoSourceAvailableException;
use MageOS\AdvancedReport\Model\Query\RawResult;
use MageOS\AdvancedReport\Model\Query\ReportRequestFactory;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;

/**
 * "Low stock with coverage": per SKU current quantity (stock dataset) combined with the units
 * sold over the last 30 days (runner call on `sales_items`), giving the average daily sales and
 * the days of coverage left. Products with stock but no sales get null coverage.
 *
 * The runner is injected as a proxy (see `di.xml`): it depends on the preset query pool holding
 * this class. Not `final` for that reason.
 */
class LowStockCoverageQuery implements PresetQueryInterface
{
    private const int SALES_WINDOW_DAYS = 30;
    private const string SALES_ACL_RESOURCE = 'MageOS_AdvancedReport::sales';
    private const string STOCK_SOURCE = 'mysql';

    public function __construct(
        private readonly DataSourcePool $dataSourcePool,
        private readonly ReportRunnerInterface $reportRunner,
        private readonly ReportRequestFactory $reportRequestFactory,
        private readonly AuthorizationInterface $authorization,
        private readonly Config $config,
    ) {
    }

    public function execute(ReportRequestInterface $request, ResolvedQuery $resolvedQuery): RawResult
    {
        $stockRows = $this->loadStock($request, $resolvedQuery);

        $warnings = [];
        $unitsSold = null;
        if ($this->authorization->isAllowed(self::SALES_ACL_RESOURCE)) {
            $unitsSold = $this->loadUnitsSold($request);
        } else {
            $warnings[] = (string) new Phrase(
                'Sales data is not available with your permissions: coverage columns are empty.'
            );
        }

        $rows = [];
        foreach ($stockRows as $stockRow) {
            $sku = (string) $stockRow['dims_raw']['sku'];
            $qty = (float) ($stockRow['metrics']['qty_on_hand'] ?? 0);
            if ($qty > 0) {
                $rows[] = $this->buildRow($stockRow, $qty, $unitsSold === null ? null : ($unitsSold[$sku] ?? 0.0));
            }
        }
        usort($rows, $this->compareCoverage(...));

        return new RawResult(
            \array_slice($rows, $resolvedQuery->offset, $resolvedQuery->limit),
            ['metrics' => [
                'qty_on_hand' => array_sum(array_column(array_column($rows, 'metrics'), 'qty_on_hand')),
            ]],
            \count($rows),
            $warnings,
        );
    }

    /**
     * @return array<int,array{dims: array<string,string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}>
     */
    private function loadStock(ReportRequestInterface $request, ResolvedQuery $resolvedQuery): array
    {
        $source = $this->dataSourcePool->get(self::STOCK_SOURCE)
            ?? throw new NoSourceAvailableException(new Phrase('The MySQL data source is not available.'));

        $stockQuery = new ResolvedQuery(
            $resolvedQuery->dataset,
            ['qty_on_hand'],
            $this->stockDimensions($resolvedQuery),
            $resolvedQuery->filters,
            null,
            $resolvedQuery->storeIds,
            [],
            null,
            0,
        );

        return $source->execute($request, $stockQuery)->rows;
    }

    /**
     * Mandatory `sku` plus `product_id`, which is 1:1 with it. Other dimensions (e.g. `source_code`)
     * would split stock rows and make `execute()` count the per-SKU units sold several times.
     *
     * @return DimensionSelection[]
     */
    private function stockDimensions(ResolvedQuery $resolvedQuery): array
    {
        $dimensions = [new DimensionSelection('sku')];
        foreach ($resolvedQuery->dimensions as $dimension) {
            if ($dimension->code === 'product_id') {
                $dimensions[] = $dimension;
            }
        }

        return $dimensions;
    }

    /**
     * @return array<string,float> Units sold in the window, by simple SKU.
     */
    private function loadUnitsSold(ReportRequestInterface $request): array
    {
        $salesRequest = $this->reportRequestFactory->fromArray([
            'dataset' => 'sales_items',
            'metrics' => ['units_sold'],
            'dimensions' => [['code' => 'sku', 'granularity' => null]],
            'dateRange' => ['type' => 'last_30_days'],
            'storeIds' => $request->getStoreIds(),
            'limit' => $this->config->getMaxRows(),
        ]);

        $unitsSold = [];
        foreach ($this->reportRunner->run($salesRequest)->getRows() as $row) {
            $unitsSold[(string) $row['dims_raw']['sku']] = (float) ($row['metrics']['units_sold'] ?? 0);
        }

        return $unitsSold;
    }

    /**
     * @param array{dims: array<string,string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>} $stockRow
     * @return array{dims: array<string,string>, dims_raw: array<string,?string>, metrics: array<string,int|float|null>}
     */
    private function buildRow(array $stockRow, float $qty, ?float $unitsSold): array
    {
        $average = $unitsSold === null ? null : $unitsSold / self::SALES_WINDOW_DAYS;

        return [
            'dims' => $stockRow['dims'],
            'dims_raw' => $stockRow['dims_raw'],
            'metrics' => [
                'qty_on_hand' => $qty,
                'units_sold_30d' => $unitsSold,
                'avg_daily_sales' => $average,
                'coverage_days' => $average !== null && $average > 0 ? $qty / $average : null,
            ],
        ];
    }

    /**
     * Coverage ascending, products without coverage last.
     *
     * @param array{metrics: array<string,int|float|null>} $left
     * @param array{metrics: array<string,int|float|null>} $right
     */
    private function compareCoverage(array $left, array $right): int
    {
        $leftCoverage = $left['metrics']['coverage_days'];
        $rightCoverage = $right['metrics']['coverage_days'];
        if ($leftCoverage === null || $rightCoverage === null) {
            return ($leftCoverage === null) <=> ($rightCoverage === null);
        }

        return $leftCoverage <=> $rightCoverage;
    }
}
