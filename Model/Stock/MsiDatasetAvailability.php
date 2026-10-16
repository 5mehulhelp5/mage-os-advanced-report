<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Stock;

use Magento\Framework\Module\Manager as ModuleManager;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DatasetAvailabilityInterface;

/**
 * Makes the configured datasets available only while every required MSI module is enabled.
 */
class MsiDatasetAvailability implements DatasetAvailabilityInterface
{
    /**
     * @param string[] $datasetCodes
     * @param string[] $requiredModules
     */
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly array $datasetCodes = [],
        private readonly array $requiredModules = []
    ) {
    }

    public function isAvailable(Dataset $dataset): bool
    {
        if (!\in_array($dataset->code, $this->datasetCodes, true)) {
            return true;
        }

        foreach ($this->requiredModules as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                return false;
            }
        }

        return true;
    }
}
