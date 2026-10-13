<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
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
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->subject = new OrderStatusLabels($resource);
    }

    public function testItReturnsAdminLabelsKeyedByStatusWhenNoStoreIsGiven(): void
    {
        $this->connection->expects(self::once())->method('fetchPairs')->with($this->select)
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
