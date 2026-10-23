<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource;

use MageOS\AdvancedReport\Model\Query\ResolvedQuery;

/**
 * Detects the distinct order base currencies inside the exact scope of a resolved query.
 */
interface CurrencyProbeInterface
{
    /**
     * Distinct, sorted base currency codes (at most two are fetched); empty when the dataset has no
     * base currency field.
     *
     * @param ResolvedQuery $query
     * @return string[]
     */
    public function getBaseCurrencies(ResolvedQuery $query): array;
}
