<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Audit;

/**
 * Renders the metric inventory as a deterministic Markdown document, one table per dataset.
 */
class InventoryMarkdownRenderer
{
    private const string FAN_OUT_MARK = '⚠';

    /**
     * @param MetricInventoryEntry[] $entries
     * @param array<string,string> $datasetLabels Dataset label by dataset code.
     */
    public function render(array $entries, array $datasetLabels): string
    {
        $byDataset = [];
        foreach ($entries as $entry) {
            $byDataset[$entry->datasetCode][] = $entry;
        }

        $sections = [];
        foreach ($byDataset as $code => $datasetEntries) {
            $sections[] = $this->renderDataset((string) $code, $datasetLabels[$code] ?? (string) $code, $datasetEntries);
        }

        return implode("\n", $sections);
    }

    /**
     * @param string $code
     * @param string $label
     * @param MetricInventoryEntry[] $entries
     */
    private function renderDataset(string $code, string $label, array $entries): string
    {
        $first = $entries[0];
        $lines = [
            \sprintf('## %s (%s)', $code, $label),
            '',
            $first->baseTable !== null
                ? \sprintf('Base table: `%s`', $first->baseTable)
                : \sprintf('Base: provider: `%s`', $first->baseProvider),
            '',
            'Fan-out dimensions: ' . $this->list($this->getFanOutDimensions($entries), 'none'),
            '',
            '| Metric | Label | Kind | Aggregate | Expression | Joins | Fan-out | Depends on |',
            '| --- | --- | --- | --- | --- | --- | --- | --- |',
        ];
        foreach ($entries as $entry) {
            $lines[] = $this->renderRow($entry);
        }

        return implode("\n", $lines) . "\n";
    }

    private function renderRow(MetricInventoryEntry $entry): string
    {
        $cells = [
            '`' . $entry->metricCode . '`' . ($entry->hidden ? ' (hidden)' : ''),
            $entry->label,
            $entry->kind,
            $entry->aggregateFunction->value ?? '',
            $entry->expression !== null ? '`' . $entry->expression . '`' : '',
            $this->list($entry->joins),
            $this->renderFanOut($entry),
            $this->list($entry->dependencies),
        ];

        return '| ' . implode(' | ', array_map($this->escape(...), $cells)) . ' |';
    }

    private function renderFanOut(MetricInventoryEntry $entry): string
    {
        if (!$entry->isFanOutRisk()) {
            return '';
        }
        $parts = $entry->fanOutJoins;
        if ($entry->fanOutDimensions !== []) {
            $parts[] = 'by ' . implode(', ', $entry->fanOutDimensions);
        }

        return self::FAN_OUT_MARK . ' ' . implode(', ', $parts);
    }

    /**
     * @param MetricInventoryEntry[] $entries
     * @return string[]
     */
    private function getFanOutDimensions(array $entries): array
    {
        $dimensions = [];
        foreach ($entries as $entry) {
            $dimensions = [...$dimensions, ...$entry->fanOutDimensions];
        }

        return array_values(array_unique($dimensions));
    }

    /**
     * @param string[] $items
     */
    private function list(array $items, string $empty = ''): string
    {
        return $items === [] ? $empty : implode(', ', $items);
    }

    private function escape(string $cell): string
    {
        return str_replace('|', '\|', $cell);
    }
}
