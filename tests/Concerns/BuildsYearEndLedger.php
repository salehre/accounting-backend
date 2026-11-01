<?php

namespace Tests\Concerns;

use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;

/**
 * A small chart of accounts, three fiscal years' worth of setup and helpers to post entries.
 * Used by the tests of the year-end entries.
 */
trait BuildsYearEndLedger
{
    /** @var array<string, int> */
    private array $a = [];

    private int $year;

    private int $nextYear;

    private function group(string $code, string $name, string $nature, string $category): int
    {
        $id = $this->makeAccount($code, $name, 1, $nature);
        DB::table('account_group_categories')->insert(['account_id' => $id, 'category' => $category]);

        return $id;
    }

    private function buildChart(): void
    {
        $assets = $this->group('1', 'Assets', 'debit', 'asset');
        $equity = $this->group('3', 'Equity', 'credit', 'equity');
        $revenue = $this->group('4', 'Revenue', 'credit', 'revenue');
        $expenses = $this->group('5', 'Expenses', 'debit', 'expense');

        $assetsKol = $this->makeAccount('11', 'Current assets', 2, 'debit', $assets);
        $equityKol = $this->makeAccount('31', 'Capital', 2, 'credit', $equity);
        $revenueKol = $this->makeAccount('41', 'Sales', 2, 'credit', $revenue);
        $expensesKol = $this->makeAccount('51', 'Operating', 2, 'debit', $expenses);

        $this->a = [
            'cash' => $this->makeAccount('1101', 'Cash', 3, 'debit', $assetsKol, true),
            'capital' => $this->makeAccount('3101', 'Capital', 3, 'credit', $equityKol, true),
            'retained' => $this->makeAccount('3102', 'Retained earnings', 3, 'credit', $equityKol, true),
            'profitLoss' => $this->makeAccount('3103', 'Profit and loss', 3, 'credit', $equityKol, true),
            'closing' => $this->makeAccount('3104', 'Closing', 3, 'credit', $equityKol, true),
            'opening' => $this->makeAccount('3105', 'Opening', 3, 'credit', $equityKol, true),
            'sales' => $this->makeAccount('4101', 'Sales', 3, 'credit', $revenueKol, true),
            'rent' => $this->makeAccount('5101', 'Rent', 3, 'debit', $expensesKol, true),
        ];

        $this->year = DB::table('fiscal_years')->insertGetId([
            'title' => '1405',
            'start_date' => '2026-03-21',
            'end_date' => '2027-03-20',
        ]);
        $this->nextYear = DB::table('fiscal_years')->insertGetId([
            'title' => '1406',
            'start_date' => '2027-03-21',
            'end_date' => '2028-03-20',
        ]);
    }

    private function configureSettings(): void
    {
        DB::table('accounting_settings')->where('id', 1)->update([
            'profit_loss_account_id' => $this->a['profitLoss'],
            'retained_earnings_account_id' => $this->a['retained'],
            'closing_account_id' => $this->a['closing'],
            'opening_account_id' => $this->a['opening'],
        ]);
    }

    /** @param array<int, array{0: string, 1: int, 2: int}> $lines [account key, debit, credit] */
    private function draftEntry(array $lines, string $date = '2026-06-01', ?int $fiscalYearId = null): int
    {
        return app(JournalEntryService::class)->createDraft([
            'fiscalYearId' => $fiscalYearId ?? $this->year,
            'entryDate' => $date,
            'description' => null,
            'lines' => array_map(fn (array $l) => [
                'accountId' => $this->a[$l[0]],
                'debit' => $l[1],
                'credit' => $l[2],
            ], $lines),
        ], $this->actor->id);
    }

    /** @param array<int, array{0: string, 1: int, 2: int}> $lines */
    private function postEntry(array $lines, string $date = '2026-06-01', ?int $fiscalYearId = null): void
    {
        app(JournalEntryService::class)->post($this->draftEntry($lines, $date, $fiscalYearId), $this->actor->id);
    }

    /** Capital 1,000,000 + sales 500,000 - rent 200,000: a profit of 300,000. */
    private function profitScenario(): void
    {
        $this->postEntry([['cash', 1000000, 0], ['capital', 0, 1000000]]);
        $this->postEntry([['cash', 500000, 0], ['sales', 0, 500000]]);
        $this->postEntry([['rent', 200000, 0], ['cash', 0, 200000]]);
    }
}
