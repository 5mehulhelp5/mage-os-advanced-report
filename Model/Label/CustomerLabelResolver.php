<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use MageOS\AdvancedReport\Model\ResourceModel\CustomerOrderNames;

/**
 * Labels normalised order emails as "Firstname Lastname <email>" using the most recent order.
 */
class CustomerLabelResolver extends AbstractLabelResolver
{
    public function __construct(
        private readonly CustomerOrderNames $customerOrderNames
    ) {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $labels = [];
        foreach ($this->customerOrderNames->getNames($values) as $email => $name) {
            $labels[$email] = $name === '' ? (string)$email : \sprintf('%s <%s>', $name, $email);
        }

        return $labels;
    }
}
