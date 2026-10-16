<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * Decides whether a declared dataset can be served in the current installation
 * (e.g. MSI-only datasets when MSI is disabled). Unavailable datasets are dropped from the registry.
 */
interface DatasetAvailabilityInterface
{
    public function isAvailable(Dataset $dataset): bool;
}
