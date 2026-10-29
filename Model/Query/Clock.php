<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * {@see ClockInterface} backed by the current GMT timestamp.
 */
class Clock implements ClockInterface
{
    public function __construct(private readonly DateTime $dateTime)
    {
    }

    public function today(string $timezone): DateTimeImmutable
    {
        $local = (new DateTimeImmutable('@' . $this->dateTime->gmtTimestamp()))
            ->setTimezone(new DateTimeZone($timezone));

        return new DateTimeImmutable($local->format('Y-m-d'));
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->dateTime->gmtTimestamp()))->setTimezone(new DateTimeZone('UTC'));
    }
}
