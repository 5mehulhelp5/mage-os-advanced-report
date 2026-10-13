<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Query;

use DateTimeImmutable;
use MageOS\AdvancedReport\Model\Query\ReportResult;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReportResult::class)]
final class ReportResultTest extends TestCase
{
    public function testItSerializesTheResultToTheDocumentedJsonShapeIncludingCurrencyCodeAndLowerIsBetter(): void
    {
        $columns = [
            [
                'code' => 'net_sales',
                'label' => 'Net sales',
                'type' => 'metric',
                'format' => 'money',
                'description' => 'Sales net of tax and shipping.',
                'nonAdditive' => false,
                'lowerIsBetter' => false,
            ],
        ];
        $rows = [
            [
                'dims' => ['order_date' => '2026-09-01'],
                'dims_raw' => ['order_date' => '2026-09-01'],
                'metrics' => ['net_sales' => 123.4],
                'comparison' => ['net_sales' => ['value' => 100.0, 'delta' => 23.4, 'delta_pct' => 0.234]],
            ],
        ];
        $totals = [
            'metrics' => ['net_sales' => 1234.5],
            'comparison' => ['net_sales' => ['value' => 1000.0, 'delta' => 234.5, 'delta_pct' => 0.2345]],
        ];

        $result = new ReportResult(
            columns: $columns,
            rows: $rows,
            totals: $totals,
            totalRowCount: 31,
            range: new ResolvedDateRange(
                new DateTimeImmutable('2026-08-27'),
                new DateTimeImmutable('2026-09-25'),
                true,
                30
            ),
            comparisonRange: new ResolvedDateRange(
                new DateTimeImmutable('2026-07-28'),
                new DateTimeImmutable('2026-08-26'),
                false,
                30
            ),
            currencyCode: 'EUR',
            warnings: [],
            source: 'mysql',
            generatedAt: new DateTimeImmutable('2026-09-25T10:00:00+00:00'),
            cached: false,
            meta: ['timezone_strategy' => 'convert_tz'],
        );

        self::assertSame(
            [
                'columns' => $columns,
                'rows' => $rows,
                'totals' => $totals,
                'total_row_count' => 31,
                'range' => ['from' => '2026-08-27', 'to' => '2026-09-25'],
                'comparison_range' => ['from' => '2026-07-28', 'to' => '2026-08-26'],
                'currency_code' => 'EUR',
                'warnings' => [],
                'source' => 'mysql',
                'generated_at' => '2026-09-25T10:00:00+00:00',
                'cached' => false,
                'meta' => ['timezone_strategy' => 'convert_tz'],
            ],
            $result->toArray()
        );
    }
}
