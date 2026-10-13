<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * Contributes additional definitions (fields, joins, dimensions) to a built dataset before validation.
 */
interface DatasetExtenderInterface
{
    /**
     * Returns the dataset extended with synthesized definitions, or the same instance when nothing applies.
     *
     * @param Dataset $dataset
     */
    public function extend(Dataset $dataset): Dataset;
}
