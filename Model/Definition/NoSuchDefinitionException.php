<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when a dataset lookup is made for a field, metric, dimension or join code
 * that is not declared on the dataset.
 */
class NoSuchDefinitionException extends LocalizedException
{
}
