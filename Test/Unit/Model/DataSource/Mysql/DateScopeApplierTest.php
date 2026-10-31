<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\DataSource\Mysql;

use DateTimeImmutable;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\DataSource\Mysql\DateScopeApplier;
use MageOS\AdvancedReport\Model\DataSource\Mysql\FieldExpressionResolver;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\Field;
use MageOS\AdvancedReport\Model\Definition\FieldType;
use MageOS\AdvancedReport\Model\Query\ReportScope;
use MageOS\AdvancedReport\Model\Query\ResolvedDateRange;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
use MageOS\AdvancedReport\Model\Timezone\StoreTimezoneMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DateScopeApplier::class)]
final class DateScopeApplierTest extends TestCase
{
    public function testItKeepsRowsWithAStoreOutsideTheTimezoneMapInTheAllStoresDateFilterAcrossTimezones(): void
    {
        $applier = $this->applier(true);
        $wheres = [];

        $applier->apply($this->select($wheres), $this->query([]), $this->connection());

        self::assertCount(2, $wheres);
        self::assertSame(
            "(main.store_id IN (1,2) AND main.created_at >= 'rome-from' AND main.created_at < 'rome-to')"
            . " OR ((main.store_id IS NULL OR main.store_id NOT IN (1,2))"
            . " AND main.created_at >= 'def-from' AND main.created_at < 'def-to')",
            $wheres[1]
        );
    }

    public function testItAddsNoFallbackBranchWhenStoresAreSelected(): void
    {
        $applier = $this->applier(true);
        $wheres = [];

        $applier->apply($this->select($wheres), $this->query([1, 2]), $this->connection());

        self::assertStringNotContainsString('IS NULL', implode(' ', $wheres));
    }

    /**
     * @param string[] $wheres
     */
    private function select(array &$wheres): Select
    {
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(
            static function (string $condition) use (&$wheres, $select): Select {
                $wheres[] = $condition;

                return $select;
            }
        );

        return $select;
    }

    private function connection(): AdapterInterface
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace(
                '?',
                \is_array($value) ? implode(',', $value) : "'" . $value . "'",
                $text
            )
        );

        return $connection;
    }

    private function applier(bool $multiple): DateScopeApplier
    {
        $resolver = $this->createStub(FieldExpressionResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (Dataset $d, string $code): string => 'main.' . $code
        );
        $map = $this->createStub(StoreTimezoneMap::class);
        $map->method('hasMultipleTimezones')->willReturn($multiple);
        $map->method('toUtcRange')->willReturn(['from' => 'wide-from', 'to' => 'wide-to']);
        $map->method('toUtcRangesByTimezone')->willReturn([
            ['timezone' => 'Europe/Rome', 'storeIds' => [1, 2], 'from' => 'rome-from', 'to' => 'rome-to'],
        ]);
        $map->method('toUtcRangeInDefaultTimezone')->willReturn(['from' => 'def-from', 'to' => 'def-to']);

        return new DateScopeApplier($resolver, $map);
    }

    /**
     * @param int[] $storeIds
     */
    private function query(array $storeIds): ResolvedQuery
    {
        $dataset = new Dataset(
            'sales_orders',
            'Orders',
            'acl',
            'sales',
            false,
            null,
            null,
            null,
            [],
            [
                Dataset::FIELD_CREATED_AT => new Field(Dataset::FIELD_CREATED_AT, 'Created', FieldType::DATE),
                Dataset::FIELD_STORE_ID => new Field(Dataset::FIELD_STORE_ID, 'Store', FieldType::INTEGER),
            ],
            [],
            [],
            []
        );
        $day = new DateTimeImmutable('2025-01-10');

        return new ResolvedQuery(
            $dataset,
            [],
            [],
            [],
            new ResolvedDateRange($day, $day, false, 1),
            $storeIds,
            [],
            null,
            0,
            new ReportScope(null, 'UTC', 0, ['UTC'])
        );
    }
}
