<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Label;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Label\StockLabelResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockLabelResolver::class)]
final class StockLabelResolverTest extends TestCase
{
    public function testItResolvesStockAndOrderStatusLabelsOnTheReportConnection(): void
    {
        $select = $this->createMock(Select::class);
        $select->expects(self::once())->method('from')->with('inventory_stock', ['stock_id', 'name'])->willReturnSelf();
        $select->expects(self::once())->method('where')->with('stock_id IN (?)', [1, 2])->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('getTableName')->willReturnArgument(0);
        $select->method('__toString')->willReturn('SQL');
        $select->method('getBind')->willReturn([]);
        $connection->expects(self::once())->method('fetchPairs')->with('T:SQL', [])->willReturn([1 => 'Default Stock']);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $resource->method('getConnectionByName')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('report');
        $guardrails = $this->createStub(Guardrails::class);
        $guardrails->method('applyTimeout')->willReturnCallback(static fn (string $sql): string => 'T:' . $sql);

        $labels = (new StockLabelResolver($resource, new ReportConnection($resource, $config), $guardrails))
            ->resolve(['1', '2']);

        self::assertSame(['1' => 'Default Stock', '2' => '2'], $labels);
    }
}
