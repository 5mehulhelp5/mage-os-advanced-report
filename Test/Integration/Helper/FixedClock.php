<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Integration\Helper;

use DateTimeImmutable;
use DateTimeZone;
use MageOS\AdvancedReport\Model\Query\ClockInterface;

/**
 * {@see ClockInterface} pinned to a fixed UTC instant, so date ranges and cart thresholds agree in tests.
 */
final readonly class FixedClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $utcInstant)
    {
        $this->now = new DateTimeImmutable($utcInstant, new DateTimeZone('UTC'));
    }

    public function today(string $timezone): DateTimeImmutable
    {
        return new DateTimeImmutable($this->now->setTimezone(new DateTimeZone($timezone))->format('Y-m-d'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
