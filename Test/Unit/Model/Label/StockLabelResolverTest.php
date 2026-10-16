<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\Label\StockLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockLabelResolver::class)]
final class StockLabelResolverTest extends TestCase
{
    public function testItResolvesStockNamesForTheStockDimension(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')->with('inventory_stock', ['stock_id', 'name'])->willReturnSelf();
        $select->expects(self::once())->method('where')->with('stock_id IN (?)', [1, 2])->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('getTableName')->willReturnArgument(0);
        $connection->expects(self::once())->method('fetchPairs')->with($select)->willReturn([1 => 'Default Stock']);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $labels = (new StockLabelResolver($resource))->resolve(['1', '2']);

        self::assertSame(['1' => 'Default Stock', '2' => '2'], $labels);
    }
}
