<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Dimension;
use MageOS\AdvancedReport\Model\Definition\Metric;

/**
 * Builds the column metadata for a report request's dimensions and metrics, in the same
 * order they were requested: dimensions first, then metrics. Labels and descriptions
 * are translated to the current locale (the result cache key includes it). Consumed by
 * {@see ReportResult::toArray()}'s `columns` entry.
 */
class ColumnMetadataBuilder
{
    /**
     * @param Dataset $dataset
     * @param ReportRequestInterface $request
     * @return array<int,array{
     *     code: string,
     *     label: string,
     *     type: string,
     *     format: ?string,
     *     description: ?string,
     *     nonAdditive: bool,
     *     lowerIsBetter: bool
     * }>
     */
    public function build(Dataset $dataset, ReportRequestInterface $request): array
    {
        $columns = [];
        foreach ($request->getDimensions() as $dimensionSelection) {
            $columns[] = $this->buildDimensionColumn($dataset, $dataset->getDimension($dimensionSelection->code));
        }
        foreach ($request->getMetrics() as $metricCode) {
            $columns[] = $this->buildMetricColumn($dataset->getMetric($metricCode));
        }

        return $columns;
    }

    /**
     * @return array{code: string, label: string, type: string, format: ?string, description: ?string, nonAdditive: bool, lowerIsBetter: bool}
     */
    private function buildDimensionColumn(Dataset $dataset, Dimension $dimension): array
    {
        $field = $dataset->fields[$dimension->fieldCode] ?? null;

        return [
            'code' => $dimension->code,
            'label' => (string) __($dimension->label),
            'type' => 'dimension',
            'format' => $field?->type->value,
            'description' => $this->translate($field?->description),
            'nonAdditive' => $dimension->nonAdditive,
            'lowerIsBetter' => false,
        ];
    }

    /**
     * @return array{code: string, label: string, type: string, format: ?string, description: ?string, nonAdditive: bool, lowerIsBetter: bool}
     */
    private function buildMetricColumn(Metric $metric): array
    {
        return [
            'code' => $metric->code,
            'label' => (string) __($metric->label),
            'type' => 'metric',
            'format' => $metric->format,
            'description' => $this->translate($metric->description),
            'nonAdditive' => false,
            'lowerIsBetter' => $metric->lowerIsBetter,
        ];
    }

    /**
     * @param string|null $text
     * @return string|null
     */
    private function translate(?string $text): ?string
    {
        return $text !== null && $text !== '' ? (string) __($text) : $text;
    }
}
