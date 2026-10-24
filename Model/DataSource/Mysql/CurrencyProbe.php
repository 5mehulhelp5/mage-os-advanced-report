<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

use MageOS\AdvancedReport\Model\DataSource\CurrencyProbeInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use Throwable;

/**
 * MySQL implementation of {@see CurrencyProbeInterface}, executed with the query guardrails.
 */
class CurrencyProbe implements CurrencyProbeInterface
{
    public function __construct(
        private readonly ReportConnection $reportConnection,
        private readonly SelectBuilder $selectBuilder,
        private readonly Guardrails $guardrails,
    ) {
    }

    /**
     * @inheritDoc
     * @throws Throwable
     */
    public function getBaseCurrencies(ResolvedQuery $query): array
    {
        $dataset = $query->dataset;
        if (!$dataset->hasField(Dataset::FIELD_BASE_CURRENCY) || !$dataset->hasField(Dataset::FIELD_CREATED_AT)) {
            return [];
        }

        $select = $this->selectBuilder->buildCurrencyProbe($query);
        $connection = $this->reportConnection->get();
        try {
            $codes = $connection->fetchCol($this->guardrails->applyTimeout((string) $select), $select->getBind());
        } catch (Throwable $error) {
            throw $this->guardrails->mapException($error);
        }

        $codes = array_values(array_unique(array_map(
            strval(...),
            array_filter($codes, static fn (mixed $code): bool => $code !== null && $code !== '')
        )));
        sort($codes);

        return $codes;
    }
}
