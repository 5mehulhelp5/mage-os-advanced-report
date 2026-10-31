<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Query;

use DateTimeImmutable;

/**
 * Calendar-year arithmetic shared by the comparison range and the comparison row alignment.
 */
final class CalendarYear
{
    /**
     * Subtracts one calendar year from `$date`, clamping 29 February onto 28 February
     * when the target year is not a leap year. The time of day is preserved.
     */
    public static function subtract(DateTimeImmutable $date): DateTimeImmutable
    {
        $year = (int) $date->format('Y') - 1;
        $month = (int) $date->format('m');
        $day = (int) $date->format('d');
        if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
            $day = 28;
        }

        return $date->setDate($year, $month, $day);
    }
}
