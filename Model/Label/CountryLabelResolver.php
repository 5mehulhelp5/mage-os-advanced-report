<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Label;

use Magento\Directory\Api\CountryInformationAcquirerInterface;

class CountryLabelResolver extends AbstractLabelResolver
{
    public function __construct(private readonly CountryInformationAcquirerInterface $countryInformation)
    {
    }

    protected function loadLabels(array $values, array $context): array
    {
        $labels = [];
        foreach ($this->countryInformation->getCountriesInfo() as $country) {
            $labels[(string)$country->getId()] = (string)$country->getFullNameLocale();
        }

        return $labels;
    }
}
