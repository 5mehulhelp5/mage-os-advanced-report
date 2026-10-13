<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config as ShippingConfig;

/**
 * Labels `carrier_method` codes as "Carrier title - Method title"; falls back to the raw code.
 */
class ShippingMethodLabelResolver extends AbstractLabelResolver
{
    public function __construct(private readonly ShippingConfig $shippingConfig)
    {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $labels = [];
        foreach ($this->shippingConfig->getAllCarriers() as $carrierCode => $carrier) {
            $carrierTitle = (string)$carrier->getConfigData('title');
            $methods = $carrier instanceof CarrierInterface ? (array)$carrier->getAllowedMethods() : [];
            foreach ($methods as $methodCode => $methodTitle) {
                $labels[$carrierCode . '_' . $methodCode] = $carrierTitle === ''
                    ? (string)$methodTitle
                    : $carrierTitle . ' - ' . $methodTitle;
            }
        }

        return $labels;
    }
}
