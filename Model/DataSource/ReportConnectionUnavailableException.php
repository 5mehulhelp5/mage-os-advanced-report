<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when the configured report database connection is undefined or cannot be established.
 */
class ReportConnectionUnavailableException extends LocalizedException
{
}
