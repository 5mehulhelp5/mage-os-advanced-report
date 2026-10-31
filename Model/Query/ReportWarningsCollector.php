<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;

/**
 * Collects the non-fatal warnings the report runner (task 044) surfaces to the UI:
 * - a non-additive dimension is in use (its rows don't sum up to the totals);
 * - low cost coverage (below {@see LOW_COST_COVERAGE_THRESHOLD}) when a margin metric
 *   (`gross_margin`/`margin_pct`) is requested;
 * - a cart report range (main or comparison) starts before the quote retention window.
 */
class ReportWarningsCollector
{
    private const float LOW_COST_COVERAGE_THRESHOLD = 0.8;

    /**
     * @var string[]
     */
    private const array MARGIN_METRICS = ['gross_margin', 'margin_pct'];

    /**
     * @param string[] $requestedMetricCodes Originally requested metric codes (before adding
     *                                       the hidden `cost_coverage` helper).
     * @param float|null $costCoverage The computed `cost_coverage` total, null when not computed
     *                                 (no margin metric requested, or the dataset has none).
     * @param QuoteRetentionWindow|null $quoteRetention Null outside the carts area.
     * @return string[]
     */
    public function collect(
        Dataset $dataset,
        ReportRequestInterface $request,
        array $requestedMetricCodes,
        ?float $costCoverage,
        ?QuoteRetentionWindow $quoteRetention = null,
        ?ResolvedDateRange $range = null,
        ?ResolvedDateRange $comparisonRange = null,
    ): array {
        return [
            ...$this->nonAdditiveDimensionWarnings($dataset, $request),
            ...$this->lowCostCoverageWarning($requestedMetricCodes, $costCoverage),
            ...$this->quoteRetentionWarning($quoteRetention, [$range, $comparisonRange]),
        ];
    }

    /**
     * @param array<int,ResolvedDateRange|null> $ranges
     * @return string[]
     */
    private function quoteRetentionWarning(?QuoteRetentionWindow $retention, array $ranges): array
    {
        if ($retention === null) {
            return [];
        }

        foreach ($ranges as $range) {
            if ($range !== null && $range->from < $retention->cutoff) {
                return [
                    (string) new Phrase(
                        'Carts not updated for more than %1 days are deleted by Magento '
                        . '(Sales > Checkout > Shopping Cart > Quote Lifetime); results before %2 are incomplete.',
                        [$retention->days, $retention->cutoff->format('Y-m-d')],
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * @return string[]
     */
    private function nonAdditiveDimensionWarnings(Dataset $dataset, ReportRequestInterface $request): array
    {
        $warnings = [];
        foreach ($request->getDimensions() as $selection) {
            $dimension = $dataset->getDimension($selection->code);
            if ($dimension->nonAdditive) {
                $warnings[] = (string) new Phrase(
                    'The "%1" dimension is not additive: totals may not equal the sum of the rows shown.',
                    [$dimension->label],
                );
            }
        }

        return $warnings;
    }

    /**
     * @param string[] $requestedMetricCodes
     * @return string[]
     */
    private function lowCostCoverageWarning(array $requestedMetricCodes, ?float $costCoverage): array
    {
        if ($costCoverage === null || !$this->requestsMarginMetrics($requestedMetricCodes)) {
            return [];
        }

        if ($costCoverage >= self::LOW_COST_COVERAGE_THRESHOLD) {
            return [];
        }

        return [
            (string) new Phrase(
                'Cost data is known for only %1% of the reported rows: margin metrics may be inaccurate.',
                [round($costCoverage * 100, 1)],
            ),
        ];
    }

    /**
     * @param string[] $metricCodes
     */
    private function requestsMarginMetrics(array $metricCodes): bool
    {
        return array_intersect(self::MARGIN_METRICS, $metricCodes) !== [];
    }
}
