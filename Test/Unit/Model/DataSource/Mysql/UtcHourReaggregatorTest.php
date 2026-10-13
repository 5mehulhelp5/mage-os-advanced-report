<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AdvancedReport\Model\DataSource\Mysql\UtcHourReaggregator;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Metric;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UtcHourReaggregator::class)]
final class UtcHourReaggregatorTest extends TestCase
{
    public function testItReAggregatesUtcHoursIntoLocalDaysForASingleTimezone(): void
    {
        $rows = [
            $this->row(['utc_hour' => '2024-03-10 22:00:00'], ['revenue' => 10, 'orders' => 1]),
            $this->row(['utc_hour' => '2024-03-10 23:00:00'], ['revenue' => 5, 'orders' => 2]),
            $this->row(['utc_hour' => '2024-03-11 09:00:00'], ['revenue' => 1, 'orders' => 1]),
        ];

        $result = $this->build([1 => 'Europe/Rome'])
            ->reaggregate($rows, 'created_at', 'day', $this->metrics(), [1]);

        self::assertSame(
            [
                $this->row(['created_at' => '2024-03-10'], ['revenue' => 10, 'orders' => 1]),
                $this->row(['created_at' => '2024-03-11'], ['revenue' => 6, 'orders' => 3]),
            ],
            $result['rows']
        );
        self::assertSame([], $result['warnings']);
    }

    public function testItReAggregatesUsingEachStoreTimezoneWhenStoresDiffer(): void
    {
        $rows = [
            $this->row(['utc_hour' => '2024-03-10 23:00:00', 'store_id' => '1'], ['revenue' => 10, 'orders' => 1]),
            $this->row(['utc_hour' => '2024-03-10 23:00:00', 'store_id' => '2'], ['revenue' => 4, 'orders' => 1]),
            $this->row(['utc_hour' => '2024-03-11 00:00:00', 'store_id' => '2'], ['revenue' => 1, 'orders' => 1]),
        ];

        $result = $this->build([1 => 'Europe/Rome', 2 => 'America/New_York'])
            ->reaggregate($rows, 'created_at', 'hour', $this->metrics(), [1, 2], 'store_id', true);

        self::assertSame(
            [
                $this->row(['created_at' => '2024-03-11 00:00'], ['revenue' => 10, 'orders' => 1]),
                $this->row(['created_at' => '2024-03-10 19:00'], ['revenue' => 4, 'orders' => 1]),
                $this->row(['created_at' => '2024-03-10 20:00'], ['revenue' => 1, 'orders' => 1]),
            ],
            $result['rows']
        );
    }

    public function testItFormatsWeekMonthYearHourOfDayAndWeekdayBuckets(): void
    {
        // 2024-03-13 is a Wednesday
        $rows = [$this->row(['utc_hour' => '2024-03-13 10:00:00'], ['revenue' => 1, 'orders' => 1])];
        $expected = [
            'week' => '2024-03-11',
            'month' => '2024-03',
            'year' => '2024',
            'hour_of_day' => '10',
            'weekday' => '3',
        ];
        foreach ($expected as $bucket => $value) {
            $result = $this->build([1 => 'UTC'], '1')
                ->reaggregate($rows, 'd', $bucket, $this->metrics(), [1]);
            self::assertSame($value, $result['rows'][0]['dims_raw']['d'], $bucket);
        }
        $sunday = $this->build([1 => 'UTC'], '0')->reaggregate($rows, 'd', 'week', $this->metrics(), [1]);
        self::assertSame('2024-03-10', $sunday['rows'][0]['dims_raw']['d']);
    }

    public function testItMergesMinMaxAndAdditiveAggregatesCorrectly(): void
    {
        $metrics = [
            new Metric('lo', 'Lo', null, 'money', false, false, AggregateFunction::MIN, 'total'),
            new Metric('hi', 'Hi', null, 'money', false, false, AggregateFunction::MAX, 'total'),
            new Metric('rev', 'Rev', null, 'money', false, false, AggregateFunction::SUM, 'total'),
            new Metric('cnt', 'Cnt', null, 'integer', false, false, AggregateFunction::COUNT, 'total'),
        ];
        $rows = [
            $this->row(['utc_hour' => '2024-03-10 10:00:00'], ['lo' => 5, 'hi' => 9, 'rev' => 1.5, 'cnt' => 2]),
            $this->row(['utc_hour' => '2024-03-10 11:00:00'], ['lo' => 3, 'hi' => 7, 'rev' => 2.5, 'cnt' => 1]),
            $this->row(['utc_hour' => '2024-03-10 12:00:00'], ['lo' => null, 'hi' => null, 'rev' => 1, 'cnt' => 1]),
        ];

        $result = $this->build([1 => 'UTC'])->reaggregate($rows, 'd', 'day', $metrics, [1]);

        self::assertSame(['lo' => 3, 'hi' => 9, 'rev' => 5.0, 'cnt' => 4], $result['rows'][0]['metrics']);
    }

    public function testItAddsCountDistinctOrderIdsAcrossHours(): void
    {
        $metrics = [
            new Metric('orders', 'Orders', null, 'integer', false, false, AggregateFunction::COUNT_DISTINCT, 'order_id'),
        ];
        $rows = [
            $this->row(['utc_hour' => '2024-03-10 10:00:00'], ['orders' => 2]),
            $this->row(['utc_hour' => '2024-03-10 11:00:00'], ['orders' => 3]),
        ];

        $result = $this->build([1 => 'UTC'])->reaggregate($rows, 'd', 'day', $metrics, [1]);

        self::assertSame(5, $result['rows'][0]['metrics']['orders']);
        self::assertSame([], $result['warnings']);
    }

    public function testItNullsUnsupportedAggregatesAndAddsAWarning(): void
    {
        $metrics = [
            new Metric('customers', 'Customers', null, 'integer', false, false, AggregateFunction::COUNT_DISTINCT, 'customer_id'),
            new Metric('avg_total', 'Average total', null, 'money', false, false, AggregateFunction::AVG, 'total'),
            new Metric('rev', 'Revenue', null, 'money', false, false, AggregateFunction::SUM, 'total'),
        ];
        $rows = [
            $this->row(['utc_hour' => '2024-03-10 10:00:00'], ['customers' => 2, 'avg_total' => 4, 'rev' => 1]),
            $this->row(['utc_hour' => '2024-03-10 11:00:00'], ['customers' => 3, 'avg_total' => 6, 'rev' => 2]),
        ];

        $result = $this->build([1 => 'UTC'])->reaggregate($rows, 'd', 'day', $metrics, [1]);

        self::assertSame(
            ['customers' => null, 'avg_total' => null, 'rev' => 3],
            $result['rows'][0]['metrics']
        );
        self::assertCount(2, $result['warnings']);
        self::assertStringContainsString('Customers', $result['warnings'][0]);
        self::assertStringContainsString('Average total', $result['warnings'][1]);
    }

    public function testItWarnsWhenATimezoneHasANonWholeHourOffset(): void
    {
        $rows = [$this->row(['utc_hour' => '2024-03-10 10:00:00'], ['revenue' => 1, 'orders' => 1])];

        $result = $this->build([1 => 'Asia/Kolkata'])->reaggregate($rows, 'd', 'day', $this->metrics(), [1]);

        self::assertCount(1, $result['warnings']);
    }

    /**
     * @param array<string,string> $dims
     * @param array<string,int|float|null> $metrics
     * @return array{dims: array<string,string>, dims_raw: array<string,string>, metrics: array<string,int|float|null>}
     */
    private function row(array $dims, array $metrics): array
    {
        return ['dims' => $dims, 'dims_raw' => $dims, 'metrics' => $metrics];
    }

    /**
     * @return Metric[]
     */
    private function metrics(): array
    {
        return [
            new Metric('revenue', 'Revenue', null, 'money', false, false, AggregateFunction::SUM, 'total'),
            new Metric('orders', 'Orders', null, 'integer', false, false, AggregateFunction::COUNT_DISTINCT, 'order_id'),
        ];
    }

    /**
     * @param array<int,string> $timezones
     */
    private function build(array $timezones, string $firstDay = '1'): UtcHourReaggregator
    {
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('getTimezone')->willReturnCallback(static fn (int $id): string => $timezones[$id]);
        $map->method('hasNonWholeHourOffset')->willReturnCallback(
            static function (array $ids, \DateTimeImmutable $at) use ($timezones): bool {
                foreach ($timezones as $tz) {
                    if ($at->setTimezone(new \DateTimeZone($tz))->getOffset() % 3600 !== 0) {
                        return true;
                    }
                }

                return false;
            }
        );
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn($firstDay);

        return new UtcHourReaggregator($map, $config);
    }
}
