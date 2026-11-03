<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportScope
{
    /**
     * The year-end closing entries and their reversals (the table alias must be "e").
     * Reports "before closing" leave them out. The posting trigger flags them the same way.
     */
    public const NOT_CLOSING_SQL = <<<'SQL'
        e.source_type NOT IN ('year_end_temporary', 'year_end_profit', 'year_end_closing')
        AND NOT EXISTS (
            SELECT 1 FROM journal_entries o
             WHERE o.id = e.reverses_entry_id
               AND o.source_type IN ('year_end_temporary', 'year_end_profit', 'year_end_closing'))
        SQL;

    /**
     * The fiscal year and the date range of a report (the whole year when no dates are given).
     *
     * @return array{year: object, from: string, to: string}
     */
    public static function resolve(int $fiscalYearId, ?string $from, ?string $to): array
    {
        $year = DB::table('fiscal_years')->where('id', $fiscalYearId)->first();
        if (! $year) {
            abort(404, 'Fiscal year not found');
        }

        $from ??= $year->start_date;
        $to ??= $year->end_date;
        $inside = "inside the fiscal year ({$year->start_date} to {$year->end_date}).";
        $errors = [];

        if ($from < $year->start_date || $from > $year->end_date) {
            $errors['from'] = 'The start date must be '.$inside;
        }
        if ($to < $year->start_date || $to > $year->end_date) {
            $errors['to'] = 'The end date must be '.$inside;
        }
        if ($errors === [] && $from > $to) {
            $errors['to'] = 'The end date must not be before the start date.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['year' => $year, 'from' => $from, 'to' => $to];
    }
}
