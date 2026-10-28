<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreJournalEntryRequest extends FormRequest
{
    // The largest integer JavaScript can represent exactly (the web app is JS).
    private const MAX_AMOUNT = 9007199254740991;

    public function authorize(): bool
    {
        return true; // permissions are enforced by the route middleware
    }

    protected function prepareForValidation(): void
    {
        // A missing debit or credit means 0.
        $lines = $this->input('lines');

        if (is_array($lines)) {
            $this->merge([
                'lines' => array_map(function ($line) {
                    if (is_array($line)) {
                        $line['debit'] = $line['debit'] ?? 0;
                        $line['credit'] = $line['credit'] ?? 0;
                    }

                    return $line;
                }, $lines),
            ]);
        }
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'entryDate' => ['required', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.accountId' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.detailId' => ['nullable', 'integer', 'exists:detail_accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.debit' => ['required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'lines.*.credit' => ['required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
        ];
    }

    /** @return array<int, \Closure> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('lines', []) as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $hasDebit = (int) ($line['debit'] ?? 0) > 0;
                    $hasCredit = (int) ($line['credit'] ?? 0) > 0;

                    // exactly one of the two must be positive
                    if ($hasDebit === $hasCredit) {
                        $validator->errors()->add(
                            "lines.$index",
                            'Each line must have either a debit or a credit amount (not both, not neither).',
                        );
                    }
                }
            },
        ];
    }
}
