<?php

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

class StatementReportsTest extends TestCase
{
    use BuildsReportScenario;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
        $this->buildChart();
        $this->configureSettings();
        $this->reportScenario(); // capital 1,000,000 | sale 500,000 | rent 200,000
    }

    /** @param array<string, mixed> $query */
    private function income(array $query = [], ?string $token = null, ?int $year = null): TestResponse
    {
        return $this->api(
            'GET',
            '/api/reports/income-statement?'.http_build_query(['fiscalYearId' => $year ?? $this->year, 'level' => 3] + $query),
            [],
            $token ?? $this->adminToken,
        );
    }

    /** @param array<string, mixed> $query */
    private function balanceSheet(array $query = [], ?string $token = null, ?int $year = null): TestResponse
    {
        return $this->api(
            'GET',
            '/api/reports/balance-sheet?'.http_build_query(['fiscalYearId' => $year ?? $this->year, 'level' => 3] + $query),
            [],
            $token ?? $this->adminToken,
        );
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function codes(array $rows): array
    {
        return array_column($rows, 'code');
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function amounts(array $rows): array
    {
        return array_column($rows, 'amount');
    }

    private function groupId(string $code): int
    {
        return (int) DB::table('accounts')->where('code', $code)->value('id');
    }

    // -----------------------------------------------------------------
    // Income statement
    // -----------------------------------------------------------------

    public function test_the_income_statement_of_the_whole_year(): void
    {
        $response = $this->income()->assertOk()
            ->assertJsonPath('from', '2026-03-21')
            ->assertJsonPath('to', '2027-03-20')
            ->assertJsonPath('level', 3)
            ->assertJsonPath('revenue.total', 500000)
            ->assertJsonPath('expenses.total', 200000)
            ->assertJsonPath('netProfit', 300000)
            ->assertJsonPath('complete', true)
            ->assertJsonPath('warnings', []);

        $this->assertSame(['4101'], $this->codes($response->json('revenue.rows')));
        $this->assertSame([500000], $this->amounts($response->json('revenue.rows')));
        $this->assertSame(['5101'], $this->codes($response->json('expenses.rows')));
        $this->assertSame([200000], $this->amounts($response->json('expenses.rows')));
        $this->assertSame('Sales', $response->json('revenue.rows.0.name'));
    }

    public function test_the_income_statement_of_a_period(): void
    {
        // only the sale falls in this period
        $this->income(['from' => '2026-06-01', 'to' => '2026-08-31'])->assertOk()
            ->assertJsonPath('revenue.total', 500000)
            ->assertJsonPath('expenses.total', 0)
            ->assertJsonPath('expenses.rows', [])
            ->assertJsonPath('netProfit', 500000);

        // only the rent
        $this->income(['from' => '2026-09-01'])->assertOk()
            ->assertJsonPath('revenue.total', 0)
            ->assertJsonPath('revenue.rows', [])
            ->assertJsonPath('expenses.total', 200000)
            ->assertJsonPath('netProfit', -200000);

        // nothing at all
        $this->income(['to' => '2026-05-31'])->assertOk()
            ->assertJsonPath('revenue.rows', [])
            ->assertJsonPath('expenses.rows', [])
            ->assertJsonPath('netProfit', 0);
    }

    public function test_the_income_statement_rolls_up_to_the_kol_and_the_group(): void
    {
        $kols = $this->income(['level' => 2])->assertOk()->assertJsonPath('netProfit', 300000);
        $this->assertSame(['41'], $this->codes($kols->json('revenue.rows')));
        $this->assertSame(['51'], $this->codes($kols->json('expenses.rows')));

        $groups = $this->income(['level' => 1])->assertOk()->assertJsonPath('netProfit', 300000);
        $this->assertSame(['4'], $this->codes($groups->json('revenue.rows')));
        $this->assertSame(['5'], $this->codes($groups->json('expenses.rows')));
        $this->assertSame(1, $groups->json('revenue.rows.0.level'));
    }

    public function test_accounts_with_detail_accounts_are_part_of_the_result(): void
    {
        $this->customersScenario(); // two more sales: 300,000 and 100,000

        $this->income()->assertOk()
            ->assertJsonPath('revenue.total', 900000)
            ->assertJsonPath('netProfit', 700000);
    }

    public function test_the_year_end_entries_do_not_change_the_income_statement(): void
    {
        $this->closeTheYear($this->adminToken);

        $this->income()->assertOk()
            ->assertJsonPath('revenue.total', 500000)
            ->assertJsonPath('expenses.total', 200000)
            ->assertJsonPath('netProfit', 300000);

        // the next year has no result yet
        $this->income([], null, $this->nextYear)->assertOk()
            ->assertJsonPath('revenue.rows', [])
            ->assertJsonPath('expenses.rows', [])
            ->assertJsonPath('netProfit', 0);
    }

    public function test_unclassified_accounts_make_the_income_statement_incomplete(): void
    {
        DB::table('account_group_categories')->where('account_id', $this->groupId('5'))->delete();

        $response = $this->income()->assertOk()
            ->assertJsonPath('complete', false)
            ->assertJsonPath('warnings.0.code', 'unclassified_accounts')
            ->assertJsonPath('expenses.rows', [])
            ->assertJsonPath('netProfit', 500000);

        $account = $response->json('warnings.0.accounts.0');
        $this->assertSame(['5101', 200000], [$account['code'], $account['balance']]);
    }

    public function test_the_income_statement_rejects_invalid_requests(): void
    {
        $cases = [
            'an unknown level' => [['level' => 4], 'level'],
            'a start date before the fiscal year' => [['from' => '2020-01-01'], 'from'],
            'an end date after the fiscal year' => [['to' => '2030-01-01'], 'to'],
            'an end date before the start date' => [['from' => '2026-09-01', 'to' => '2026-06-01'], 'to'],
            'a wrong date format' => [['from' => '01-06-2026'], 'from'],
        ];

        foreach ($cases as $case => [$query, $errorKey]) {
            $response = $this->income($query);

            $response->assertStatus(422);
            $this->assertArrayHasKey($errorKey, $response->json('errors') ?? [], "The case \"{$case}\" was not rejected for \"{$errorKey}\"");
        }

        $this->income([], null, 999999)->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');
        $this->api('GET', '/api/reports/income-statement', [], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');
    }

    // -----------------------------------------------------------------
    // Balance sheet
    // -----------------------------------------------------------------

    public function test_the_balance_sheet_at_the_end_of_the_year(): void
    {
        $response = $this->balanceSheet()->assertOk()
            ->assertJsonPath('date', '2027-03-20')
            ->assertJsonPath('assets.total', 1300000)
            ->assertJsonPath('liabilities.total', 0)
            ->assertJsonPath('equity.total', 1000000)
            ->assertJsonPath('currentPeriodResult', 300000)
            ->assertJsonPath('liabilitiesAndEquity', 1300000)
            ->assertJsonPath('difference', 0)
            ->assertJsonPath('balanced', true)
            ->assertJsonPath('complete', true)
            ->assertJsonPath('warnings', []);

        $this->assertSame(['1101'], $this->codes($response->json('assets.rows')));
        $this->assertSame([1300000], $this->amounts($response->json('assets.rows')));
        $this->assertSame([], $response->json('liabilities.rows'));
        $this->assertSame(['3101'], $this->codes($response->json('equity.rows')));
        $this->assertSame([1000000], $this->amounts($response->json('equity.rows')));
    }

    public function test_the_balance_sheet_at_an_earlier_date(): void
    {
        // the rent of 2026-09-01 is not in yet, the sale is
        $this->balanceSheet(['date' => '2026-06-30'])->assertOk()
            ->assertJsonPath('assets.total', 1500000)
            ->assertJsonPath('equity.total', 1000000)
            ->assertJsonPath('currentPeriodResult', 500000)
            ->assertJsonPath('balanced', true);

        $this->balanceSheet(['date' => '2026-05-31'])->assertOk()
            ->assertJsonPath('assets.total', 1000000)
            ->assertJsonPath('currentPeriodResult', 0)
            ->assertJsonPath('balanced', true);

        $this->balanceSheet(['date' => '2026-03-25'])->assertOk()
            ->assertJsonPath('assets.rows', [])
            ->assertJsonPath('balanced', true);
    }

    public function test_the_balance_sheet_rolls_up_to_the_kol_and_the_group(): void
    {
        $kols = $this->balanceSheet(['level' => 2])->assertOk()->assertJsonPath('balanced', true);
        $this->assertSame(['11'], $this->codes($kols->json('assets.rows')));
        $this->assertSame(['31'], $this->codes($kols->json('equity.rows')));

        $groups = $this->balanceSheet(['level' => 1])->assertOk()->assertJsonPath('balanced', true);
        $this->assertSame(['1'], $this->codes($groups->json('assets.rows')));
        $this->assertSame(['3'], $this->codes($groups->json('equity.rows')));
    }

    public function test_liabilities_are_shown_with_their_natural_sign(): void
    {
        $group = $this->makeAccount('2', 'Liabilities', 1, 'credit');
        DB::table('account_group_categories')->insert(['account_id' => $group, 'category' => 'liability']);
        $kol = $this->makeAccount('21', 'Payables', 2, 'credit', $group);
        $this->a['payables'] = $this->makeAccount('2101', 'Suppliers', 3, 'credit', $kol, true);

        $this->postEntry([['cash', 100000, 0], ['payables', 0, 100000]], '2026-10-01');

        $response = $this->balanceSheet()->assertOk()
            ->assertJsonPath('assets.total', 1400000)
            ->assertJsonPath('liabilities.total', 100000)
            ->assertJsonPath('equity.total', 1000000)
            ->assertJsonPath('currentPeriodResult', 300000)
            ->assertJsonPath('liabilitiesAndEquity', 1400000)
            ->assertJsonPath('balanced', true);

        $this->assertSame(['2101'], $this->codes($response->json('liabilities.rows')));
        $this->assertSame([100000], $this->amounts($response->json('liabilities.rows')));
    }

    public function test_the_year_end_entries_do_not_change_the_balance_sheet(): void
    {
        $this->closeTheYear($this->adminToken);

        // the same year still shows the figures before the closing
        $this->balanceSheet()->assertOk()
            ->assertJsonPath('assets.total', 1300000)
            ->assertJsonPath('currentPeriodResult', 300000)
            ->assertJsonPath('balanced', true);

        // the next year starts with the opening entry: the result of the last year is in retained earnings
        $next = $this->balanceSheet([], null, $this->nextYear)->assertOk()
            ->assertJsonPath('date', '2028-03-20')
            ->assertJsonPath('assets.total', 1300000)
            ->assertJsonPath('equity.total', 1300000)
            ->assertJsonPath('currentPeriodResult', 0)
            ->assertJsonPath('balanced', true);

        $this->assertSame(['3101', '3102'], $this->codes($next->json('equity.rows')));
        $this->assertSame([1000000, 300000], $this->amounts($next->json('equity.rows')));
    }

    public function test_unclassified_accounts_unbalance_the_balance_sheet_and_say_so(): void
    {
        DB::table('account_group_categories')->where('account_id', $this->groupId('5'))->delete();

        $response = $this->balanceSheet()->assertOk()
            ->assertJsonPath('balanced', false)
            ->assertJsonPath('complete', false)
            ->assertJsonPath('difference', -200000)
            ->assertJsonPath('warnings.0.code', 'unclassified_accounts');

        $account = $response->json('warnings.0.accounts.0');
        $this->assertSame(['5101', 200000], [$account['code'], $account['balance']]);
    }

    public function test_the_balance_sheet_rejects_invalid_requests(): void
    {
        $cases = [
            'a date before the fiscal year' => [['date' => '2020-01-01'], 'date'],
            'a date after the fiscal year' => [['date' => '2030-01-01'], 'date'],
            'a wrong date format' => [['date' => '01-06-2026'], 'date'],
            'an unknown level' => [['level' => 4], 'level'],
        ];

        foreach ($cases as $case => [$query, $errorKey]) {
            $response = $this->balanceSheet($query);

            $response->assertStatus(422);
            $this->assertArrayHasKey($errorKey, $response->json('errors') ?? [], "The case \"{$case}\" was not rejected for \"{$errorKey}\"");
        }

        $this->balanceSheet([], null, 999999)->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_who_may_read_the_statements(): void
    {
        $this->api('GET', "/api/reports/income-statement?fiscalYearId={$this->year}")->assertStatus(401);
        $this->api('GET', "/api/reports/balance-sheet?fiscalYearId={$this->year}")->assertStatus(401);

        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $this->income([], $viewer)->assertOk();
        $this->balanceSheet([], $viewer)->assertOk();

        DB::table('roles')->insert(['name' => 'no_reports', 'permissions' => json_encode(['entries.read'])]);
        $noReports = $this->tokenFor($this->makeUser('no_reports'));
        $this->income([], $noReports)->assertStatus(403);
        $this->balanceSheet([], $noReports)->assertStatus(403);
    }
}
