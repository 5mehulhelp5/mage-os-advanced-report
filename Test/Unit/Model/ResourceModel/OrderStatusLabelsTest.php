<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\ResourceModel\OrderStatusLabels;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderStatusLabels::class)]
#[AllowMockObjectsWithoutExpectations]
final class OrderStatusLabelsTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private Select&MockObject $select;
    private OrderStatusLabels $subject;

    protected function setUp(): void
    {
        $this->select = $this->createMock(Select::class);
        $this->select->method('from')->willReturnSelf();
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($this->select);
        $this->connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', (string) $value, $text)
        );
        $this->select->method('__toString')->willReturn('SQL');
        $this->select->method('getBind')->willReturn([]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $resource->method('getConnectionByName')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $config = $this->createStub(Config::class);
        $config->method('getConnectionName')->willReturn('report');
        $guardrails = $this->createStub(Guardrails::class);
        $guardrails->method('applyTimeout')->willReturnCallback(static fn (string $sql): string => 'T:' . $sql);
        $this->subject = new OrderStatusLabels($resource, new ReportConnection($resource, $config), $guardrails);
    }

    public function testItReturnsAdminLabelsKeyedByStatusWhenNoStoreIsGiven(): void
    {
        $this->connection->expects(self::once())->method('fetchPairs')->with('T:SQL', [])
            ->willReturn(['pending' => 'Pending', 'closed' => 'Closed']);

        self::assertSame(['pending' => 'Pending', 'closed' => 'Closed'], $this->subject->getLabels(null));
    }

    public function testItDoesNotJoinStoreLabelsWhenNoStoreIsGiven(): void
    {
        $this->select->expects(self::never())->method('joinLeft');
        $this->connection->method('fetchPairs')->willReturn([]);

        $this->subject->getLabels(null);
        $this->subject->getLabels(0);
    }

    public function testItJoinsStoreLabelsForTheGivenStore(): void
    {
        $this->select->expects(self::once())->method('joinLeft')
            ->with(
                ['l' => 'sales_order_status_label'],
                'l.status = s.status AND l.store_id = 3',
                []
            )->willReturnSelf();
        $this->connection->method('fetchPairs')->willReturn([]);

        $this->subject->getLabels(3);
    }

    public function testItPrefersTheStoreLabelAndFallsBackToTheAdminLabel(): void
    {
        $this->select->method('joinLeft')->willReturnSelf();
        $this->select->expects(self::once())->method('columns')
            ->with(['s.status', "COALESCE(NULLIF(l.label, ''), s.label)"])
            ->willReturnSelf();
        $this->connection->method('fetchPairs')->willReturn([]);

        $this->subject->getLabels(3);
    }

    public function testItCastsTheFetchedLabelsToStrings(): void
    {
        $this->connection->method('fetchPairs')->willReturn([10 => 5]);

        self::assertSame(['10' => '5'], $this->subject->getLabels(null));
    }
}
