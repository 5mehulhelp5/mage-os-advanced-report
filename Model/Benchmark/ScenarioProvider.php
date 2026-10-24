<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Benchmark;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\AdvancedReport\Model\Query\ClockInterface;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\ReportScopeResolver;

/**
 * Default benchmark matrix, configured in `etc/di.xml`: dated presets x date ranges x stores x
 * comparison, dateless presets x stores, then the explicitly configured "expensive" scenarios.
 *
 * `last_12_months` is not a {@see DateRangeType}: it is built as a custom range from today minus 12
 * months plus one day to today, in the timezone of the all-stores scope.
 */
class ScenarioProvider
{
    private const string LAST_12_MONTHS = 'last_12_months';

    /**
     * @param StoreManagerInterface $storeManager
     * @param ReportScopeResolver $scopeResolver
     * @param ClockInterface $clock
     * @param string[] $presets Presets with a date range.
     * @param string[] $datelessPresets
     * @param string[] $dateRanges `last_12_months` or a {@see DateRangeType} value.
     * @param array<string,array{preset?: string, request?: array<string,mixed>, dateRange?: string, compareTo?: string}> $expensiveScenarios
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ReportScopeResolver $scopeResolver,
        private readonly ClockInterface $clock,
        private readonly array $presets = [],
        private readonly array $datelessPresets = [],
        private readonly array $dateRanges = [],
        private readonly array $expensiveScenarios = [],
    ) {
    }

    /**
     * @return Scenario[]
     */
    public function getScenarios(): array
    {
        $storeOptions = $this->storeOptions();
        $scenarios = [];
        foreach ($this->presets as $preset) {
            foreach ($this->dateRanges as $rangeCode) {
                foreach ($storeOptions as $storeCode => $storeIds) {
                    foreach ([CompareMode::NONE, CompareMode::PREVIOUS_PERIOD] as $compareTo) {
                        $scenarios[] = new Scenario(
                            \sprintf('%s:%s:%s:%s', $preset, $rangeCode, $storeCode, $compareTo->value),
                            $preset,
                            [],
                            $this->dateRange($rangeCode),
                            $storeIds,
                            $compareTo,
                        );
                    }
                }
            }
        }
        foreach ($this->datelessPresets as $preset) {
            foreach ($storeOptions as $storeCode => $storeIds) {
                $scenarios[] = new Scenario(
                    \sprintf('%s:%s', $preset, $storeCode),
                    $preset,
                    [],
                    new DateRange(DateRangeType::LAST_30_DAYS),
                    $storeIds,
                    CompareMode::NONE,
                );
            }
        }
        foreach ($this->expensiveScenarios as $code => $definition) {
            $scenarios[] = new Scenario(
                (string) $code,
                $definition['preset'] ?? null,
                $definition['request'] ?? [],
                $this->dateRange($definition['dateRange'] ?? 'last_30_days'),
                [],
                CompareMode::from($definition['compareTo'] ?? CompareMode::NONE->value),
            );
        }

        return $scenarios;
    }

    /**
     * @return array<string,int[]> option code => store ids ([] is all stores)
     */
    private function storeOptions(): array
    {
        $options = ['all_stores' => []];
        $stores = $this->storeManager->getStores();
        $first = reset($stores);
        if ($first !== false) {
            $options['store_' . (int) $first->getId()] = [(int) $first->getId()];
        }

        return $options;
    }

    private function dateRange(string $code): DateRange
    {
        if ($code !== self::LAST_12_MONTHS) {
            return new DateRange(DateRangeType::from($code));
        }

        $today = $this->clock->today($this->scopeResolver->resolve([])->timezone);

        return new DateRange(
            DateRangeType::CUSTOM,
            $today->modify('-12 months')->modify('+1 day')->format('Y-m-d'),
            $today->format('Y-m-d'),
        );
    }
}
