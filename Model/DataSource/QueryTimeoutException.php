<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when a report query exceeds the configured execution time limit.
 */
class QueryTimeoutException extends LocalizedException
{
}
