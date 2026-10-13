<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by {@see ReportRequestFactory} when a report request payload is structurally
 * malformed: missing required keys, wrong value types, or a value outside a fixed
 * vocabulary (date range type, compare mode, filter operator). Semantic validation
 * (ACL, dataset/metric/dimension existence, guardrails) happens later.
 */
class InvalidRequestException extends LocalizedException
{
}
