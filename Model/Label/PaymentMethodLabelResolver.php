<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Payment\Helper\Data as PaymentHelper;

class PaymentMethodLabelResolver extends AbstractLabelResolver
{
    public function __construct(private readonly PaymentHelper $paymentHelper)
    {
    }

    protected function loadLabels(array $values, array $context): array
    {
        return array_map(strval(...), $this->paymentHelper->getPaymentMethodList());
    }
}
