<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Audit;

use MageOS\AdvancedReport\Model\Audit\InventoryMarkdownRenderer;
use MageOS\AdvancedReport\Model\Audit\MetricInventoryEntry;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryMarkdownRenderer::class)]
final class InventoryMarkdownRendererTest extends TestCase
{
    private const array LABELS = ['a' => 'Alpha', 'b' => 'Beta'];

    public function testItRendersOneMarkdownSectionPerDataset(): void
    {
        $output = $this->render([$this->entry(), $this->entry(metricCode: 'm2'), $this->entry('b')]);

        self::assertSame(2, substr_count($output, "\n## ") + (int) str_starts_with($output, '## '));
        self::assertStringContainsString('## a (Alpha)', $output);
        self::assertStringContainsString('## b (Beta)', $output);
        self::assertStringContainsString('Base table: `sales_order`', $output);
        self::assertStringContainsString(
            '| Metric | Label | Kind | Aggregate | Expression | Joins | Fan-out | Depends on |',
            $output
        );
    }

    public function testItRendersAMarkdownRowPerMetricWithJoinsAndDependencies(): void
    {
        $output = $this->render([$this->entry(joins: ['j1', 'j2'], dependencies: ['timezone', 'currency'])]);

        self::assertStringContainsString(
            '| `m` | M label | aggregate | sum | `main.f` | j1, j2 |  | timezone, currency |',
            $output
        );
    }

    public function testItMarksHiddenMetrics(): void
    {
        self::assertStringContainsString('| `m` (hidden) |', $this->render([$this->entry(hidden: true)]));
    }

    public function testItMarksFanOutRiskMetricsInMarkdownOutput(): void
    {
        $output = $this->render([$this->entry(fanOutJoins: ['items'], fanOutDimensions: ['sku'], additive: true)]);

        self::assertStringContainsString('| ⚠ items, by sku |', $output);
    }

    public function testItListsFanOutDimensionsInEachMarkdownDatasetSection(): void
    {
        $output = $this->render([
            $this->entry(fanOutDimensions: ['sku', 'category'], additive: true),
            $this->entry('b'),
        ]);

        self::assertStringContainsString('Fan-out dimensions: sku, category', $output);
        self::assertStringContainsString('Fan-out dimensions: none', $output);
    }

    public function testItRendersTheBaseProviderWhenADatasetHasNoBaseTable(): void
    {
        $output = $this->render([$this->entry(baseTable: null, baseProvider: 'Acme\\StockProvider')]);

        self::assertStringContainsString('Base: provider: `Acme\\StockProvider`', $output);
        self::assertStringNotContainsString('Base table', $output);
    }

    public function testItEscapesPipeCharactersInMarkdownExpressions(): void
    {
        $output = $this->render([$this->entry(expression: 'a | b')]);

        self::assertStringContainsString('`a \| b`', $output);
    }

    /**
     * @param MetricInventoryEntry[] $entries
     */
    private function render(array $entries): string
    {
        return (new InventoryMarkdownRenderer())->render($entries, self::LABELS);
    }

    /**
     * @param string[] $joins
     * @param string[] $fanOutJoins
     * @param string[] $fanOutDimensions
     * @param string[] $dependencies
     */
    private function entry(
        string $dataset = 'a',
        string $metricCode = 'm',
        bool $hidden = false,
        ?string $expression = 'main.f',
        ?string $baseTable = 'sales_order',
        ?string $baseProvider = null,
        array $joins = [],
        array $fanOutJoins = [],
        array $fanOutDimensions = [],
        array $dependencies = [],
        bool $additive = false,
    ): MetricInventoryEntry {
        return new MetricInventoryEntry(
            $dataset,
            $metricCode,
            'M label',
            MetricInventoryEntry::KIND_AGGREGATE,
            $hidden,
            AggregateFunction::SUM,
            $expression,
            $baseTable,
            $baseProvider,
            $joins,
            $fanOutJoins,
            $fanOutDimensions,
            $dependencies,
            $additive,
        );
    }
}
