<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Store\Model\StoreManagerInterface;

class StoreLabelResolver extends AbstractLabelResolver
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $labels = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $labels[(string)$store->getId()] = (string)$store->getName();
        }

        return $labels;
    }
}
