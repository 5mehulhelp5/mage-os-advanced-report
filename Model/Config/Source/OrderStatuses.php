<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Every sales order status, without an empty option. Unlike the core
 * {@see \Magento\Sales\Model\Config\Source\Order\Status}, this is not restricted to a subset
 * of order states and never adds a "please select" option, since counted statuses are a
 * multiselect configuration.
 */
class OrderStatuses implements OptionSourceInterface
{
    /**
     * @param CollectionFactory $statusCollectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $statusCollectionFactory,
    ) {
    }

    /**
     * Return every sales order status as value-label pairs, without an empty option.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->statusCollectionFactory->create() as $status) {
            $options[] = [
                'value' => (string) $status->getData('status'),
                'label' => (string) $status->getData('label'),
            ];
        }

        return $options;
    }
}
