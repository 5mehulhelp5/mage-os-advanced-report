<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Phrase;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\FilterOperator;
use MageOS\AdvancedReport\Model\Definition\Formula\Formula;
use MageOS\AdvancedReport\Model\Definition\Granularity;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Definition\NoSuchDefinitionException;
use MageOS\AdvancedReport\Model\Security\DatasetAccess;

/**
 * Semantically validates a {@see ReportRequestInterface} against the semantic layer, ACL
 * and performance guardrails before any query runs.
 */
class RequestValidator
{
    private const string ACL_RESOURCE_CONFIG = 'MageOS_AdvancedReport::config';
    private const string SOURCE_MYSQL = 'mysql';
    private const string EXPRESSION_PROVIDER_FIRST_ORDER = 'first_order';
    private const int FIRST_ORDER_MAX_MONTHS = 12;
    private const int MAX_DAY_GRANULARITY_DAYS = 400;
    private const int MAX_HOUR_GRANULARITY_DAYS = 31;

    /**
     * @param DefinitionRegistry $definitionRegistry
     * @param AuthorizationInterface $authorization
     * @param DateRangeResolver $dateRangeResolver
     * @param Config $config
     * @param DatasetAccess $datasetAccess
     * @param StoreManagerInterface $storeManager
     * @param ReportScopeResolver $reportScopeResolver
     * @param MonetaryRequestDetector $monetaryRequestDetector
     * @param BaseCurrencyChecker $baseCurrencyChecker
     */
    public function __construct(
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly AuthorizationInterface $authorization,
        private readonly DateRangeResolver $dateRangeResolver,
        private readonly Config $config,
        private readonly DatasetAccess $datasetAccess,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReportScopeResolver $reportScopeResolver,
        private readonly MonetaryRequestDetector $monetaryRequestDetector,
        private readonly BaseCurrencyChecker $baseCurrencyChecker,
    ) {
    }

    /**
     * @param ReportRequestInterface $request
     * @param bool $checkAcl Skipped only by the trusted `advanced-report:explain` CLI, where
     *                       no admin session exists.
     * @throws InvalidRequestException
     * @throws AuthorizationException
     */
    public function validate(ReportRequestInterface $request, bool $checkAcl = true): void
    {
        $dataset = $this->definitionRegistry->getDataset($request->getDataset());

        if ($checkAcl) {
            $this->datasetAccess->assertAllowed($dataset);
            $this->assertSourceOverrideAllowed($request);
        }

        $this->assertMetricsValid($dataset, $request);
        $this->assertDimensionsValid($dataset, $request);
        $this->assertFiltersValid($dataset, $request);
        if ($checkAcl) {
            $this->assertFieldAclAllowed($dataset, $request);
        }
        $this->assertLimitValid($request);
        $this->assertOffsetValid($request);
        $this->assertSortValid($request);
        $this->assertStoreIdsValid($request);
        $this->assertSingleBaseCurrency($dataset, $request);
        $this->assertPresetValid($dataset, $request, $checkAcl);

        if (!$dataset->dateless) {
            $resolvedRange = $this->dateRangeResolver->resolve(
                $request->getDateRange(),
                $this->reportScopeResolver->resolve($request->getStoreIds())
            );
            $this->assertGranularityLimits($request, $resolvedRange);
            $this->assertFirstOrderRangeLimit($dataset, $request, $resolvedRange);
        }
    }

    /**
     * @param string $aclResource
     * @throws AuthorizationException
     */
    private function assertAllowed(string $aclResource): void
    {
        if (!$this->authorization->isAllowed($aclResource)) {
            throw new AuthorizationException(
                new Phrase('You are not allowed to query the resource "%1".', [$aclResource])
            );
        }
    }

    /**
     * @param ReportRequestInterface $request
     * @throws AuthorizationException
     */
    private function assertSourceOverrideAllowed(ReportRequestInterface $request): void
    {
        if ($request->getSource() !== null) {
            $this->assertAllowed(self::ACL_RESOURCE_CONFIG);
        }
    }

    /**
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     */
    private function assertMetricsValid(Dataset $dataset, ReportRequestInterface $request): void
    {
        foreach ($request->getMetrics() as $metricCode) {
            $metric = $dataset->metrics[$metricCode] ?? null;
            if ($metric === null) {
                throw new InvalidRequestException(
                    new Phrase('Unknown metric "%1" for dataset "%2".', [$metricCode, $dataset->code])
                );
            }
            if ($metric->hidden) {
                throw new InvalidRequestException(new Phrase('Metric "%1" is not selectable.', [$metricCode]));
            }
            if ($metric->isVirtual()) {
                $this->assertVirtualMetricAllowed($dataset, $metric, $request);
            }
        }
    }

    /**
     * A virtual metric only has a value when produced by the preset's own preset query,
     * so the request must run through that same preset, on the same dataset.
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @param ReportRequestInterface $request
     */
    private function assertVirtualMetricAllowed(Dataset $dataset, Metric $metric, ReportRequestInterface $request): void
    {
        $presetCode = $request->getPreset();
        if ($presetCode === null) {
            throw new InvalidRequestException(
                new Phrase('Metric "%1" is only available through a preset query.', [$metric->code])
            );
        }

        try {
            $preset = $this->definitionRegistry->getPreset($presetCode);
        } catch (NoSuchDefinitionException) {
            throw new InvalidRequestException(new Phrase('Unknown preset "%1".', [$presetCode]));
        }

        if ($preset->dataset !== $dataset->code || $preset->presetQuery === null) {
            throw new InvalidRequestException(
                new Phrase('Metric "%1" is only available through a preset query.', [$metric->code])
            );
        }
    }

    /**
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     */
    private function assertDimensionsValid(Dataset $dataset, ReportRequestInterface $request): void
    {
        foreach ($request->getDimensions() as $dimensionSelection) {
            $dimension = $dataset->dimensions[$dimensionSelection->code] ?? null;
            if ($dimension === null) {
                throw new InvalidRequestException(
                    new Phrase('Unknown dimension "%1" for dataset "%2".', [$dimensionSelection->code, $dataset->code])
                );
            }

            $granularity = $dimensionSelection->granularity;
            if ($granularity !== null && !\in_array($granularity, $dimension->getGranularities(), true)) {
                throw new InvalidRequestException(
                    new Phrase(
                        'Granularity "%1" is not allowed for dimension "%2".',
                        [$granularity->value, $dimensionSelection->code]
                    )
                );
            }
        }
    }

    /**
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     */
    private function assertFiltersValid(Dataset $dataset, ReportRequestInterface $request): void
    {
        foreach ($request->getFilters() as $filter) {
            $field = $dataset->fields[$filter->field] ?? null;
            if ($field === null) {
                throw new InvalidRequestException(
                    new Phrase('Unknown field "%1" for dataset "%2".', [$filter->field, $dataset->code])
                );
            }
            if (!\in_array($filter->operator, $field->type->allowedOperators(), true)) {
                throw new InvalidRequestException(
                    new Phrase(
                        'Operator "%1" is not allowed for field "%2".',
                        [$filter->operator->value, $filter->field]
                    )
                );
            }
            $this->assertFilterValueValid($filter);
        }
    }

    /**
     * Rejects a request that reads a gated field (as dimension, filter or metric input, which
     * also covers sorting) without holding the field's ACL resource.
     *
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     * @throws AuthorizationException
     */
    private function assertFieldAclAllowed(Dataset $dataset, ReportRequestInterface $request): void
    {
        $fieldCodes = array_map(
            static fn (FilterCondition $filter): string => $filter->field,
            $request->getFilters()
        );
        foreach ($request->getDimensions() as $selection) {
            $fieldCodes[] = $dataset->dimensions[$selection->code]->fieldCode;
        }
        foreach ($request->getMetrics() as $metricCode) {
            array_push($fieldCodes, ...$this->metricFieldCodes($dataset, $dataset->metrics[$metricCode]));
        }

        foreach (array_unique($fieldCodes) as $fieldCode) {
            $acl = $dataset->fields[$fieldCode]->acl ?? null;
            if ($acl !== null) {
                $this->assertAllowed($acl);
            }
        }
    }

    /**
     * Field codes a metric reads, following derived metric formulas down to their aggregates.
     *
     * @param Dataset $dataset
     * @param Metric $metric
     * @param string[] $visited
     * @return string[]
     */
    private function metricFieldCodes(Dataset $dataset, Metric $metric, array $visited = []): array
    {
        if (\in_array($metric->code, $visited, true)) {
            return [];
        }
        $visited[] = $metric->code;

        $codes = array_values(array_filter([$metric->fieldCode, $metric->conditionFieldCode]));
        if ($metric->formula === null) {
            return $codes;
        }

        foreach (Formula::fromString($metric->formula)->getReferences() as $reference) {
            if (isset($dataset->metrics[$reference])) {
                array_push($codes, ...$this->metricFieldCodes($dataset, $dataset->metrics[$reference], $visited));
            }
        }

        return $codes;
    }

    /**
     * @param FilterCondition $filter
     */
    private function assertFilterValueValid(FilterCondition $filter): void
    {
        match ($filter->operator) {
            FilterOperator::IN, FilterOperator::NIN => $this->assertNonEmptyArrayValue($filter),
            FilterOperator::BETWEEN => $this->assertExactlyTwoValues($filter),
            default => null,
        };
    }

    /**
     * @param FilterCondition $filter
     */
    private function assertNonEmptyArrayValue(FilterCondition $filter): void
    {
        if (!\is_array($filter->value) || $filter->value === []) {
            throw new InvalidRequestException(
                new Phrase(
                    'Operator "%1" requires a non-empty array value for field "%2".',
                    [$filter->operator->value, $filter->field]
                )
            );
        }
    }

    /**
     * @param FilterCondition $filter
     */
    private function assertExactlyTwoValues(FilterCondition $filter): void
    {
        if (!\is_array($filter->value) || \count($filter->value) !== 2) {
            throw new InvalidRequestException(
                new Phrase('Operator "between" requires exactly two values for field "%1".', [$filter->field])
            );
        }
    }

    /**
     * @param ReportRequestInterface $request
     */
    private function assertLimitValid(ReportRequestInterface $request): void
    {
        $limit = $request->getLimit();
        $maxRows = $this->config->getMaxRows();
        if ($limit !== null && $limit < 1) {
            throw new InvalidRequestException(new Phrase('The limit must be at least 1.'));
        }
        if ($limit !== null && $limit > $maxRows) {
            throw new InvalidRequestException(
                new Phrase('Limit %1 exceeds the maximum of %2 rows.', [$limit, $maxRows])
            );
        }
    }

    /**
     * @param ReportRequestInterface $request
     */
    private function assertOffsetValid(ReportRequestInterface $request): void
    {
        if ($request->getOffset() < 0) {
            throw new InvalidRequestException(new Phrase('The offset must not be negative.'));
        }
    }

    /**
     * @param ReportRequestInterface $request
     */
    private function assertSortValid(ReportRequestInterface $request): void
    {
        $selectable = $request->getMetrics();
        foreach ($request->getDimensions() as $dimensionSelection) {
            $selectable[] = $dimensionSelection->code;
        }

        foreach ($request->getSort() as $sort) {
            if (!\in_array($sort->by, $selectable, true)) {
                throw new InvalidRequestException(
                    new Phrase('Cannot sort by "%1": it is not a selected metric or dimension.', [$sort->by])
                );
            }
            if (!\in_array(strtolower($sort->direction), ['asc', 'desc'], true)) {
                throw new InvalidRequestException(
                    new Phrase('Unknown sort direction "%1".', [$sort->direction])
                );
            }
        }
    }

    /**
     * @param ReportRequestInterface $request
     */
    private function assertStoreIdsValid(ReportRequestInterface $request): void
    {
        $storeIds = $request->getStoreIds();
        if ($storeIds === []) {
            return;
        }

        $knownIds = array_map(
            static fn (StoreInterface $store): int => (int)$store->getId(),
            $this->storeManager->getStores(false)
        );
        foreach ($storeIds as $storeId) {
            if (!\in_array($storeId, $knownIds, true)) {
                throw new InvalidRequestException(new Phrase('Unknown store id "%1".', [$storeId]));
            }
        }
    }

    /**
     * Rejects monetary requests spanning stores with different base currencies (amounts are
     * never converted); a `base_currency_code` filter does not bypass the rule.
     *
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     */
    private function assertSingleBaseCurrency(Dataset $dataset, ReportRequestInterface $request): void
    {
        if (!$dataset->hasField(Dataset::FIELD_STORE_ID)
            || !$this->monetaryRequestDetector->isMonetary($dataset, $request)
        ) {
            return;
        }

        $storeIds = $request->getStoreIds();
        if (!$this->baseCurrencyChecker->hasMixedCurrencies($storeIds)) {
            return;
        }

        throw new InvalidRequestException(
            new Phrase(
                'The selected stores use different base currencies (%1): select stores that share one base currency to report amounts.',
                [implode(', ', $this->baseCurrencyChecker->getCurrencyCodes($storeIds))]
            )
        );
    }

    /**
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     * @param bool $checkAcl Whether the extra datasets the preset reads must be readable too.
     * @throws AuthorizationException
     */
    private function assertPresetValid(Dataset $dataset, ReportRequestInterface $request, bool $checkAcl): void
    {
        $presetCode = $request->getPreset();
        if ($presetCode === null) {
            return;
        }

        try {
            $preset = $this->definitionRegistry->getPreset($presetCode);
        } catch (NoSuchDefinitionException) {
            throw new InvalidRequestException(new Phrase('Unknown preset "%1".', [$presetCode]));
        }

        if ($preset->dataset !== $dataset->code) {
            throw new InvalidRequestException(
                new Phrase('Preset "%1" does not belong to dataset "%2".', [$presetCode, $dataset->code])
            );
        }

        if ($checkAcl) {
            $this->datasetAccess->assertPresetAllowed($preset);
        }
    }

    /**
     * @param ReportRequestInterface $request
     * @param ResolvedDateRange $range
     */
    private function assertGranularityLimits(ReportRequestInterface $request, ResolvedDateRange $range): void
    {
        foreach ($request->getDimensions() as $dimensionSelection) {
            match ($dimensionSelection->granularity) {
                Granularity::DAY => $this->assertWithinDays($range, self::MAX_DAY_GRANULARITY_DAYS, 'day'),
                Granularity::HOUR => $this->assertWithinDays($range, self::MAX_HOUR_GRANULARITY_DAYS, 'hour'),
                default => null,
            };
        }
    }

    /**
     * @param ResolvedDateRange $range
     * @param int $maxDays
     * @param string $granularityLabel
     */
    private function assertWithinDays(ResolvedDateRange $range, int $maxDays, string $granularityLabel): void
    {
        if ($range->days > $maxDays) {
            throw new InvalidRequestException(
                new Phrase('Granularity "%1" is limited to %2 days.', [$granularityLabel, $maxDays])
            );
        }
    }

    /**
     * Guards fields whose mysql mapping uses the `first_order` expression provider
     * (e.g. `customer_type`, `new_customer_orders`, `returning_customer_orders`) against
     * expensive unbounded ranges.
     *
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     * @param ResolvedDateRange $range
     */
    private function assertFirstOrderRangeLimit(
        Dataset $dataset,
        ReportRequestInterface $request,
        ResolvedDateRange $range,
    ): void {
        $source = $request->getSource() ?? self::SOURCE_MYSQL;
        if ($source !== self::SOURCE_MYSQL || !$this->usesFirstOrderExpression($dataset, $request)) {
            return;
        }

        $maxTo = $range->from->modify(\sprintf('+%d months', self::FIRST_ORDER_MAX_MONTHS));
        if ($range->to > $maxTo) {
            throw new InvalidRequestException(
                new Phrase(
                    'New versus returning customer analysis is limited to %1 months on MySQL.',
                    [self::FIRST_ORDER_MAX_MONTHS]
                )
            );
        }
    }

    /**
     * Whether any field used by the request's metrics, dimensions or filters is mapped,
     * for mysql, through the `first_order` expression provider.
     *
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     */
    private function usesFirstOrderExpression(Dataset $dataset, ReportRequestInterface $request): bool
    {
        $fieldCodes = [];
        foreach ($request->getMetrics() as $metricCode) {
            $fieldCode = ($dataset->metrics[$metricCode] ?? null)?->fieldCode;
            if ($fieldCode !== null) {
                $fieldCodes[] = $fieldCode;
            }
        }
        foreach ($request->getDimensions() as $dimensionSelection) {
            $dimension = $dataset->dimensions[$dimensionSelection->code] ?? null;
            if ($dimension !== null) {
                $fieldCodes[] = $dimension->fieldCode;
            }
        }
        foreach ($request->getFilters() as $filter) {
            $fieldCodes[] = $filter->field;
        }

        foreach (array_unique($fieldCodes) as $fieldCode) {
            $mapping = ($dataset->fields[$fieldCode] ?? null)?->sourceMappings[self::SOURCE_MYSQL] ?? null;
            if ($mapping?->expressionProvider === self::EXPRESSION_PROVIDER_FIRST_ORDER) {
                return true;
            }
        }

        return false;
    }
}
