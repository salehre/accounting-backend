<?php

namespace Tests\Unit;

use App\Support\DateFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateFormatTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function knownDates(): array
    {
        return [
            'the first day of 1405' => ['2026-03-21', '1405/01/01'],
            'the last day of 1405 (not a leap year)' => ['2027-03-20', '1405/12/29'],
            'the first day of 1404' => ['2025-03-21', '1404/01/01'],
            'the last day of 1403 (a leap year)' => ['2025-03-20', '1403/12/30'],
            'the first day of 1403' => ['2024-03-20', '1403/01/01'],
            'the first day of Mehr 1405' => ['2026-09-23', '1405/07/01'],
            'a day in Aban 1405' => ['2026-10-26', '1405/08/04'],
        ];
    }

    #[DataProvider('knownDates')]
    public function test_gregorian_dates_are_converted_to_jalali(string $gregorian, string $jalali): void
    {
        $this->assertSame($jalali, DateFormat::show($gregorian, 'jalali'));
    }

    public function test_the_gregorian_calendar_leaves_the_date_alone(): void
    {
        $this->assertSame('2026-03-21', DateFormat::show('2026-03-21', 'gregorian'));
    }

    public function test_a_timestamp_is_cut_to_the_date(): void
    {
        $this->assertSame('1405/01/01', DateFormat::show('2026-03-21 13:45:10+03:30', 'jalali'));
    }
}
