<?php

namespace App\Support;

use Morilog\Jalali\CalendarUtils;

final class DateFormat
{
    public const CALENDARS = ['jalali', 'gregorian'];

    /**
     * Shows a date as it is stored ("2026-03-21", gregorian) or as a Jalali date ("1405/01/01").
     * Dates are always STORED as gregorian; only the display changes.
     */
    public static function show(string $isoDate, string $calendar): string
    {
        if ($calendar !== 'jalali') {
            return $isoDate;
        }

        [$year, $month, $day] = array_map('intval', explode('-', substr($isoDate, 0, 10)));
        [$jalaliYear, $jalaliMonth, $jalaliDay] = CalendarUtils::toJalali($year, $month, $day);

        return sprintf('%04d/%02d/%02d', $jalaliYear, $jalaliMonth, $jalaliDay);
    }
}
