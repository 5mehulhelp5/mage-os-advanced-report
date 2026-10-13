<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown in developer mode when the merged semantic layer (`report_datasets.xml`)
 * contains one or more invalid dataset or preset definitions. In any other mode the
 * invalid definitions are dropped and logged instead ({@see DefinitionValidator}).
 */
class InvalidDefinitionException extends LocalizedException
{
}
