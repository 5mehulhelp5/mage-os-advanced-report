<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Store\Model\StoreManagerInterface;

class WebsiteLabelResolver extends AbstractLabelResolver
{
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $labels = [];
        foreach ($this->storeManager->getWebsites(true) as $website) {
            $labels[(string)$website->getId()] = (string)$website->getName();
        }

        return $labels;
    }
}
