<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by {@see SourceResolver} when no registered data source can execute a request:
 * either an explicit source override does not exist or does not support the request, or no
 * source in the pool supports it once the preset's source restriction, if any, is applied.
 */
class NoSourceAvailableException extends LocalizedException
{
}
