<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * {@see ClockInterface} backed by the Magento locale/timezone service.
 */
class Clock implements ClockInterface
{
    public function __construct(private readonly TimezoneInterface $timezone)
    {
    }

    public function today(): DateTimeImmutable
    {
        $date = $this->timezone->date(null, null, true, false);

        return DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
    }
}
