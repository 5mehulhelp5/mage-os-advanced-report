<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Preset;

use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;

/**
 * Units of simple products sold inside bundles over the last 30 days, by simple SKU.
 *
 * The `sales_items` dataset reads top-level lines only, so bundle children are read here with the same rules:
 * counted statuses, request stores, the `last_30_days` window in the store timezone converted to UTC, and
 * `qty_ordered - qty_canceled`. Configurable children are not read: their parent line already carries the
 * child SKU and quantity.
 */
class BundleChildUnitsLoader
{
    public function __construct(
        private readonly ReportConnection $reportConnection,
        private readonly Guardrails $guardrails,
        private readonly CountedStatusCondition $countedStatusCondition,
        private readonly DateRangeResolver $dateRangeResolver,
        private readonly StoreTimezoneMap $storeTimezoneMap,
    ) {
    }

    /**
     * @param int[] $storeIds Empty means all stores.
     * @return array{units: array<string,float>, warnings: string[]}
     */
    public function load(array $storeIds, ReportScope $scope): array
    {
        $connection = $this->reportConnection->get();
        $select = $this->buildSelect($storeIds, $scope);

        try {
            $fetched = $connection->fetchPairs($this->guardrails->applyTimeout((string) $select), $select->getBind());
        } catch (\Throwable $error) {
            throw $this->guardrails->mapException($error);
        }

        $warnings = [];
        $truncation = $this->guardrails->getTruncationWarning(\count($fetched));
        if ($truncation !== null) {
            $warnings[] = (string) $truncation;
            $fetched = \array_slice($fetched, 0, $this->guardrails->getMaxRows(), true);
        }

        return [
            'units' => array_map(floatval(...), $fetched),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param int[] $storeIds
     */
    private function buildSelect(array $storeIds, ReportScope $scope): Select
    {
        $connection = $this->reportConnection->get();
        $items = $this->reportConnection->getTableName('sales_order_item');
        $range = $this->dateRangeResolver->resolve(new DateRange(DateRangeType::LAST_30_DAYS), $scope);
        $utc = $this->storeTimezoneMap->toUtcRange($range, $storeIds);

        $select = $connection->select()
            ->from(['bc' => $items], [
                'sku' => 'bc.sku',
                'units' => new \Zend_Db_Expr('SUM(COALESCE(bc.qty_ordered, 0) - COALESCE(bc.qty_canceled, 0))'),
            ])
            ->join(['bp' => $items], 'bp.item_id = bc.parent_item_id', [])
            ->join(['o' => $this->reportConnection->getTableName('sales_order')], 'o.entity_id = bc.order_id', [])
            ->where("bp.product_type = 'bundle'")
            ->where($this->countedStatusCondition->build('o.status', $connection))
            ->where('o.created_at >= ?', $utc['from'])
            ->where('o.created_at < ?', $utc['to'])
            ->group('bc.sku');
        if ($storeIds !== []) {
            $select->where('o.store_id IN (?)', $storeIds);
        }

        // One row more than the cap tells whether the result is truncated.
        return $select->limit($this->guardrails->getMaxRows() + 1);
    }
}
