<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Model\Config\Source;

use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\ResourceModel\Order\Status\Collection;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;
use MageOS\AdvancedReport\Model\Config\Source\OrderStatuses;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderStatuses::class)]
final class OrderStatusesTest extends TestCase
{
    public function testItListsEveryOrderStatusWithoutAnEmptyOption(): void
    {
        $processing = $this->statusItem('processing', 'Processing');
        $canceled = $this->statusItem('canceled', 'Canceled');

        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$processing, $canceled]));

        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $source = new OrderStatuses($collectionFactory);
        $options = $source->toOptionArray();

        self::assertSame(
            [
                ['value' => 'processing', 'label' => 'Processing'],
                ['value' => 'canceled', 'label' => 'Canceled'],
            ],
            $options
        );
        self::assertNotContains(['value' => '', 'label' => ''], $options);
    }

    private function statusItem(string $status, string $label): Status
    {
        $item = $this->createStub(Status::class);
        // A callback, not willReturnMap: PHPUnit 10 (bundled with Magento 2.4.8) also matches
        // getData()'s default $index argument, so a one-argument map never matches there.
        $item->method('getData')->willReturnCallback(
            static fn (string $key): ?string => ['status' => $status, 'label' => $label][$key] ?? null
        );

        return $item;
    }
}
