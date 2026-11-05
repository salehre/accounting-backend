<?php

namespace Tests\Feature\Reports;

use App\Services\Exports\ReportExporter as X;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use BuildsReportScenario;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
        $this->buildChart();
        $this->configureSettings();
        $this->reportScenario(); // capital 1,000,000 | sale 500,000 | rent 200,000
    }

    /**
     * The values of the test come first: with the + operator the array on the LEFT wins.
     * Dates are gregorian unless a test asks otherwise.
     *
     * @param array<string, mixed> $query
     */
    private function export(string $report, array $query = [], ?string $token = null): TestResponse
    {
        return $this->api(
            'GET',
            "/api/reports/{$report}/export?".http_build_query($query + ['fiscalYearId' => $this->year, 'calendar' => 'gregorian']),
            [],
            $token ?? $this->adminToken,
        );
    }

    /**
     * Opens the downloaded file with the spreadsheet reader and returns the rows of the first sheet.
     * Every row has at least 12 cells.
     *
     * @return array<int, array<int, mixed>>
     */
    private function rowsOf(TestResponse $response): array
    {
        $response->assertOk();
        $this->assertStringStartsWith(self::XLSX, (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));

        $path = $response->baseResponse->getFile()->getPathname();

        $reader = new Reader();
        $reader->open($path);

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_pad(array_values($row->toArray()), 12, '');
            }
            break; // the first sheet is enough
        }

        $reader->close();
        @unlink($path);

        return $rows;
    }

    /**
     * The rows after the header row (the row whose first cell is $firstHeader).
     *
     * @param array<int, array<int, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    private function after(array $rows, string $firstHeader): array
    {
        foreach ($rows as $index => $row) {
            if ($row[0] === $firstHeader) {
                return array_slice($rows, $index + 1);
            }
        }

        $this->fail("The header \"{$firstHeader}\" was not found in the file.");
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function lineStartingWith(array $rows, string $prefix): string
    {
        foreach ($rows as $row) {
            if (is_string($row[0]) && str_starts_with($row[0], $prefix)) {
                return $row[0];
            }
        }

        $this->fail("No line starts with \"{$prefix}\".");
    }

    /**
     * @param array<int, array<int, mixed>> $table
     * @return array<int, array<int, mixed>>
     */
    private function slice(array $table, int $from, int $length, int $width): array
    {
        return array_map(fn (array $row) => array_slice($row, 0, $width), array_slice($table, $from, $length));
    }

    /** The ledger of the cash account, whole year, as the file must show it. */
    private function assertCashLedger(array $rows): void
    {
        $table = $this->after($rows, X::COLUMN_DATE);

        $this->assertSame(X::OPENING_BALANCE, $table[0][2]);
        $this->assertSame(0, $table[0][9]);

        $this->assertSame([
            ['2026-04-01', 1, '', '1101', 'Cash', '', '', 1000000, 0, 1000000],
            ['2026-06-01', 2, '', '1101', 'Cash', '', '', 500000, 0, 1500000],
            ['2026-09-01', 3, '', '1101', 'Cash', '', '', 0, 200000, 1300000],
        ], $this->slice($table, 1, 3, 10));

        $this->assertSame(X::PERIOD_TOTAL, $table[4][2]);
        $this->assertSame([1500000, 200000], [$table[4][7], $table[4][8]]);
        $this->assertSame(X::CLOSING_BALANCE, $table[5][2]);
        $this->assertSame(1300000, $table[5][9]);
    }

    /** The journal book of the whole year, as the file must show it. */
    private function assertJournalRows(array $rows): void
    {
        $table = $this->after($rows, X::COLUMN_ENTRY_NUMBER);
        $manual = X::SOURCE_TYPES['manual'];

        $this->assertSame([
            [1, '2026-04-01', '', $manual, '1101', 'Cash', '', '', 1000000, 0],
            [1, '2026-04-01', '', $manual, '3101', 'Capital', '', '', 0, 1000000],
            [2, '2026-06-01', '', $manual, '1101', 'Cash', '', '', 500000, 0],
            [2, '2026-06-01', '', $manual, '4101', 'Sales', '', '', 0, 500000],
            [3, '2026-09-01', '', $manual, '5101', 'Rent', '', '', 200000, 0],
            [3, '2026-09-01', '', $manual, '1101', 'Cash', '', '', 0, 200000],
        ], $this->slice($table, 0, 6, 10));

        $this->assertSame(X::TOTAL, $table[6][0]);
        $this->assertSame([1700000, 1700000], [$table[6][8], $table[6][9]]);
    }

    // -----------------------------------------------------------------
    // Who may export
    // -----------------------------------------------------------------

    public function test_exporting_is_a_separate_permission(): void
    {
        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $accountant = $this->tokenFor($this->makeUser('accountant'));

        $calls = [
            'trial-balance' => [],
            'ledger' => ['accountId' => $this->a['cash']],
            'income-statement' => [],
            'balance-sheet' => [],
            'journal' => [],
        ];

        foreach ($calls as $report => $query) {
            $this->api('GET', "/api/reports/{$report}/export?fiscalYearId={$this->year}")->assertStatus(401);

            // a viewer may read the report but not take the data out
            $this->export($report, $query, $viewer)->assertStatus(403);
            $this->api('GET', "/api/reports/{$report}?".http_build_query($query + ['fiscalYearId' => $this->year]), [], $viewer)->assertOk();

            // accountants and administrators may
            $this->rowsOf($this->export($report, $query, $accountant));
            $this->rowsOf($this->export($report, $query));
        }
    }

    public function test_exports_are_logged(): void
    {
        $this->rowsOf($this->export('trial-balance', ['level' => 2]));

        $log = DB::table('audit_logs')->where('action', 'export')->where('entity', 'report')->first();

        $this->assertNotNull($log);
        $this->assertSame($this->actor->id, (int) $log->user_id);

        $details = json_decode((string) $log->details, true);
        $this->assertSame('trial-balance', $details['report']);
        $this->assertSame($this->year, $details['query']['fiscalYearId']);
        $this->assertSame('2', (string) $details['query']['level']);
    }

    // -----------------------------------------------------------------
    // The files
    // -----------------------------------------------------------------

    public function test_the_trial_balance_file(): void
    {
        $rows = $this->rowsOf($this->export('trial-balance'));

        $this->assertSame(X::TITLE_TRIAL_BALANCE, $rows[0][0]);
        $this->assertSame(X::FISCAL_YEAR.' 1405', $rows[1][0]);

        $table = $this->after($rows, X::COLUMN_CODE);

        $this->assertSame([
            ['1101', 'Cash', 0, 0, 1500000, 200000, 1300000, 0],
            ['3101', 'Capital', 0, 0, 0, 1000000, 0, 1000000],
            ['4101', 'Sales', 0, 0, 0, 500000, 0, 500000],
            ['5101', 'Rent', 0, 0, 200000, 0, 200000, 0],
        ], $this->slice($table, 0, 4, 8));

        $this->assertSame([X::TOTAL, '', 0, 0, 1700000, 1700000, 1500000, 1500000], array_slice($table[4], 0, 8));
        $this->assertSame(X::BALANCED, $table[5][0]);
    }

    public function test_the_trial_balance_file_with_detail_accounts(): void
    {
        $this->customersScenario();

        $rows = $this->rowsOf($this->export('trial-balance', ['withDetails' => 1]));
        $header = $this->after($rows, X::COLUMN_CODE);
        $this->assertNotEmpty($header);

        // the header row has two more columns
        $headerRow = null;
        foreach ($rows as $row) {
            if ($row[0] === X::COLUMN_CODE) {
                $headerRow = $row;
            }
        }
        $this->assertSame(X::COLUMN_DETAIL_CODE, $headerRow[2]);

        $customers = array_values(array_filter($header, fn (array $row) => $row[0] === '1103'));
        $this->assertCount(2, $customers);
        $this->assertSame(['CA', 'Customer A', 300000], [$customers[0][2], $customers[0][3], $customers[0][8]]);
        $this->assertSame(['CB', 'Customer B', 100000], [$customers[1][2], $customers[1][3], $customers[1][8]]);

        $cash = array_values(array_filter($header, fn (array $row) => $row[0] === '1101'))[0];
        $this->assertSame(['', ''], [$cash[2], $cash[3]]);
    }

    public function test_the_ledger_file(): void
    {
        $rows = $this->rowsOf($this->export('ledger', ['accountId' => $this->a['cash']]));

        $this->assertSame(X::TITLE_LEDGER, $rows[0][0]);
        $this->assertStringContainsString(X::ACCOUNT.' 1101 - Cash', $this->lineStartingWith($rows, X::FISCAL_YEAR));

        $this->assertCashLedger($rows);
    }

    public function test_the_ledger_file_of_one_detail_account(): void
    {
        $customers = $this->customersScenario();

        $rows = $this->rowsOf($this->export('ledger', ['accountId' => $customers['customers'], 'detailId' => $customers['b']]));
        $this->assertStringContainsString('CB - Customer B', $this->lineStartingWith($rows, X::FISCAL_YEAR));

        $table = $this->after($rows, X::COLUMN_DATE);
        $this->assertSame(['2026-07-02', 'Customer B', 100000, 0, 100000], [$table[1][0], $table[1][5], $table[1][7], $table[1][8], $table[1][9]]);
        $this->assertSame(100000, $table[3][9]); // the closing balance
    }

    public function test_the_journal_file(): void
    {
        $rows = $this->rowsOf($this->export('journal'));

        $this->assertSame(X::TITLE_JOURNAL, $rows[0][0]);
        $this->assertJournalRows($rows);
    }

    public function test_the_journal_file_shows_the_year_end_entries_and_their_type(): void
    {
        $this->closeTheYear($this->adminToken);

        $table = $this->after($this->rowsOf($this->export('journal')), X::COLUMN_ENTRY_NUMBER);
        $types = array_unique(array_column($table, 3));

        foreach (['year_end_temporary', 'year_end_profit', 'year_end_closing'] as $type) {
            $this->assertContains(X::SOURCE_TYPES[$type], $types);
        }

        $next = $this->after($this->rowsOf($this->export('journal', ['fiscalYearId' => $this->nextYear])), X::COLUMN_ENTRY_NUMBER);
        $this->assertSame(X::SOURCE_TYPES['year_end_opening'], $next[0][3]);
    }

    public function test_the_journal_file_can_be_limited_by_entry_numbers(): void
    {
        $rows = $this->rowsOf($this->export('journal', ['fromNumber' => 2, 'toNumber' => 2]));
        $table = $this->after($rows, X::COLUMN_ENTRY_NUMBER);

        $this->assertSame([2, 2], [$table[0][0], $table[1][0]]);
        $this->assertSame(X::TOTAL, $table[2][0]);
        $this->assertSame([500000, 500000], [$table[2][8], $table[2][9]]);
    }

    public function test_the_income_statement_file(): void
    {
        $rows = $this->rowsOf($this->export('income-statement', ['level' => 3]));
        $table = array_values(array_filter($this->after($rows, X::COLUMN_CODE), fn (array $row) => $row[0] !== ''));

        $this->assertSame([
            [X::SECTION_REVENUE, '', ''],
            ['4101', 'Sales', 500000],
            [X::TOTAL_REVENUE, '', 500000],
            [X::SECTION_EXPENSES, '', ''],
            ['5101', 'Rent', 200000],
            [X::TOTAL_EXPENSES, '', 200000],
            [X::NET_PROFIT, '', 300000],
        ], $this->slice($table, 0, 7, 3));
    }

    public function test_an_incomplete_income_statement_says_so(): void
    {
        $group = (int) DB::table('accounts')->where('code', '5')->value('id');
        DB::table('account_group_categories')->where('account_id', $group)->delete();

        $rows = $this->rowsOf($this->export('income-statement', ['level' => 3]));

        $this->lineStartingWith($rows, X::WARNING_PREFIX);

        $unclassified = array_values(array_filter($rows, fn (array $row) => $row[0] === '5101'));
        $this->assertCount(1, $unclassified);
        $this->assertSame(['5101', 'Rent', 200000], array_slice($unclassified[0], 0, 3));
    }

    public function test_the_balance_sheet_file(): void
    {
        $rows = $this->rowsOf($this->export('balance-sheet', ['level' => 3]));

        $this->assertStringStartsWith(X::DATE, $this->lineStartingWith($rows, X::DATE));

        $table = array_values(array_filter($this->after($rows, X::COLUMN_CODE), fn (array $row) => $row[0] !== ''));

        $this->assertSame([
            [X::SECTION_ASSETS, '', ''],
            ['1101', 'Cash', 1300000],
            [X::TOTAL_ASSETS, '', 1300000],
            [X::SECTION_LIABILITIES, '', ''],
            [X::TOTAL_LIABILITIES, '', 0],
            [X::SECTION_EQUITY, '', ''],
            ['3101', 'Capital', 1000000],
            [X::TOTAL_EQUITY, '', 1000000],
            [X::CURRENT_RESULT, '', 300000],
            [X::TOTAL_LIABILITIES_EQUITY, '', 1300000],
            [X::DIFFERENCE, '', 0],
            [X::BALANCED, '', ''],
        ], $this->slice($table, 0, 12, 3));
    }

    // -----------------------------------------------------------------
    // Dates
    // -----------------------------------------------------------------

    public function test_dates_are_jalali_unless_gregorian_is_asked_for(): void
    {
        // no calendar given: Jalali
        $default = $this->rowsOf($this->api('GET', "/api/reports/trial-balance/export?fiscalYearId={$this->year}", [], $this->adminToken));
        $this->assertSame(X::RANGE.' 1405/01/01 '.X::TO.' 1405/12/29', $this->lineStartingWith($default, X::RANGE));

        $gregorian = $this->rowsOf($this->export('trial-balance'));
        $this->assertSame(X::RANGE.' 2026-03-21 '.X::TO.' 2027-03-20', $this->lineStartingWith($gregorian, X::RANGE));

        // the dates of the lines
        $ledger = $this->rowsOf($this->export('ledger', ['accountId' => $this->a['cash'], 'calendar' => 'jalali']));
        $table = $this->after($ledger, X::COLUMN_DATE);
        $this->assertSame(['1405/01/12', '1405/03/11', '1405/06/10'], array_column(array_slice($table, 1, 3), 0));

        $sheet = $this->rowsOf($this->export('balance-sheet', ['calendar' => 'jalali']));
        $this->assertSame(X::DATE.' 1405/12/29', $this->lineStartingWith($sheet, X::DATE));
    }

    // -----------------------------------------------------------------
    // Big exports
    // -----------------------------------------------------------------

    public function test_a_big_ledger_is_read_in_chunks_without_losing_a_line(): void
    {
        config(['accounting.export_chunk' => 2]); // the 3 lines of the cash account need two reads

        $this->assertCashLedger($this->rowsOf($this->export('ledger', ['accountId' => $this->a['cash']])));
    }

    public function test_a_big_journal_is_read_in_chunks_without_losing_an_entry(): void
    {
        config(['accounting.export_chunk' => 1]); // one entry per read

        $this->assertJournalRows($this->rowsOf($this->export('journal')));
    }

    public function test_an_export_above_the_limit_is_refused(): void
    {
        config(['accounting.export_max_rows' => 2]);

        $ledger = $this->export('ledger', ['accountId' => $this->a['cash']]);
        $ledger->assertStatus(422);
        $this->assertStringContainsString('rows', $ledger->json('message'));

        $this->export('journal')->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Input
    // -----------------------------------------------------------------

    public function test_invalid_requests_are_rejected(): void
    {
        $cash = $this->a['cash'];

        $cases = [
            ['trial-balance', ['calendar' => 'hijri'], 'calendar'],
            ['trial-balance', ['level' => 4], 'level'],
            ['trial-balance', ['from' => '2020-01-01'], 'from'],
            ['ledger', [], 'accountId'],
            ['ledger', ['accountId' => $cash, 'to' => '2030-01-01'], 'to'],
            ['journal', ['fromNumber' => 3, 'toNumber' => 2], 'toNumber'],
            ['income-statement', ['level' => 4], 'level'],
            ['balance-sheet', ['date' => '1999-01-01'], 'date'],
        ];

        foreach ($cases as [$report, $query, $errorKey]) {
            $response = $this->export($report, $query);

            $response->assertStatus(422);
            $this->assertArrayHasKey($errorKey, $response->json('errors') ?? [], "{$report}: not rejected for \"{$errorKey}\"");
        }

        $this->api('GET', '/api/reports/trial-balance/export', [], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');
    }
}
