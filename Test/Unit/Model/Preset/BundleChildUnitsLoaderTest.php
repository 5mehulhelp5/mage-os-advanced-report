<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Preset;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\CountedStatusCondition;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Preset\BundleChildUnitsLoader;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeResolver;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;
use MageOS\AdvancedReport\Test\Unit\Helper\SelectTestTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(BundleChildUnitsLoader::class)]
final class BundleChildUnitsLoaderTest extends TestCase
{
    use SelectTestTrait;

    private Mysql&Stub $adapter;

    public function testItReadsCountedBundleChildrenOfTheLast30DaysInTheRequestStores(): void
    {
        $captured = [];
        $loader = $this->createLoader('pfx_', [], $captured);

        $result = $loader->load([2, 3], $this->scope());

        $sql = $this->normalizeSql($captured[0]);
        self::assertStringContainsString('FROM `pfx_sales_order_item` AS `bc`', $sql);
        self::assertStringContainsString('INNER JOIN `pfx_sales_order_item` AS `bp` ON bp.item_id = bc.parent_item_id', $sql);
        self::assertStringContainsString('INNER JOIN `pfx_sales_order` AS `o` ON o.entity_id = bc.order_id', $sql);
        self::assertStringContainsString("bp.product_type = 'bundle'", $sql);
        self::assertStringContainsString('o.status NOT IN', $sql);
        self::assertStringContainsString("o.created_at >= '2026-05-01 00:00:00'", $sql);
        self::assertStringContainsString("o.created_at < '2026-05-31 00:00:00'", $sql);
        self::assertStringContainsString('o.store_id IN (2, 3)', $sql);
        self::assertStringContainsString('SUM(COALESCE(bc.qty_ordered, 0) - COALESCE(bc.qty_canceled, 0))', $sql);
        self::assertStringContainsString('GROUP BY `bc`.`sku`', $sql);
        self::assertStringContainsString('LIMIT 3', $sql);
        self::assertSame(['units' => [], 'warnings' => []], $result);
    }

    public function testItDoesNotFilterStoresWhenTheRequestHasNone(): void
    {
        $captured = [];
        $this->createLoader('', [], $captured)->load([], $this->scope());

        self::assertStringNotContainsString('store_id', $this->normalizeSql($captured[0]));
    }

    public function testItReturnsUnitsBySkuAndWarnsWhenTheCapIsExceeded(): void
    {
        $captured = [];
        $loader = $this->createLoader('', ['A' => '2.0000', 'B' => '1.0000', 'C' => '9.0000'], $captured);

        $result = $loader->load([], $this->scope());

        self::assertSame(['A' => 2.0, 'B' => 1.0], $result['units']);
        self::assertCount(1, $result['warnings']);
    }

    /**
     * @param array<string,string> $pairs
     * @param string[] $captured
     */
    private function createLoader(string $prefix, array $pairs, array &$captured): BundleChildUnitsLoader
    {
        $this->adapter = $this->createSqlAdapter();
        $this->adapter->method('fetchPairs')->willReturnCallback(
            static function (string $sql) use ($pairs, &$captured): array {
                $captured[] = $sql;

                return $pairs;
            }
        );
        $adapter = $this->adapter;
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnectionByName')->willReturn($adapter);
        $resource->method('getTableName')->willReturnCallback(static fn (string $name): string => $prefix . $name);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('default');
        $config->method('getCountedStatuses')->willReturn([]);

        $guardrails = $this->createStub(Guardrails::class);
        $guardrails->method('applyTimeout')->willReturnArgument(0);
        $guardrails->method('getMaxRows')->willReturn(2);
        $guardrails->method('getTruncationWarning')->willReturnCallback(
            static fn (int $count): ?Phrase => $count > 2 ? new Phrase('truncated') : null
        );

        $resolver = $this->createStub(DateRangeResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static function (DateRange $range): ResolvedDateRange {
                self::assertSame(DateRangeType::LAST_30_DAYS, $range->type);

                return new ResolvedDateRange(
                    new \DateTimeImmutable('2026-05-01'),
                    new \DateTimeImmutable('2026-05-30'),
                    true,
                    30
                );
            }
        );
        $timezoneMap = $this->createStub(StoreTimezoneMap::class);
        $timezoneMap->method('toUtcRange')->willReturn(['from' => '2026-05-01 00:00:00', 'to' => '2026-05-31 00:00:00']);

        return new BundleChildUnitsLoader(
            new ReportConnection($resource, $config),
            $guardrails,
            new CountedStatusCondition($config),
            $resolver,
            $timezoneMap,
        );
    }

    private function scope(): ReportScope
    {
        return new ReportScope(null, 'UTC', 0, ['UTC']);
    }
}
