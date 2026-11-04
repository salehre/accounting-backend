<?php

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

class TrialBalanceReportTest extends TestCase
{
    use BuildsReportScenario;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
        $this->buildChart();
        $this->configureSettings();
        $this->reportScenario();
    }

    /** @param array<string, mixed> $query */
    private function report(array $query = [], ?string $token = null, ?int $year = null): TestResponse
    {
        return $this->api(
            'GET',
            '/api/reports/trial-balance?'.http_build_query(['fiscalYearId' => $year ?? $this->year] + $query),
            [],
            $token ?? $this->adminToken,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function byCode(TestResponse $response): array
    {
        return collect($response->json('rows'))->keyBy('code')->all();
    }

    /**
     * The account codes of a keyed list. PHP turns numeric string keys such as '1101' into integers,
     * so they are converted back to strings before comparing.
     *
     * @param array<string|int, mixed> $rows
     * @return array<int, string>
     */
    private function codesOf(array $rows): array
    {
        return array_map('strval', array_keys($rows));
    }

    /** @param array<string, mixed> $row */
    private function numbers(array $row): array
    {
        return [
            $row['openingDebit'], $row['openingCredit'],
            $row['periodDebit'], $row['periodCredit'],
            $row['closingDebit'], $row['closingCredit'],
        ];
    }

    // -----------------------------------------------------------------
    // The numbers
    // -----------------------------------------------------------------

    public function test_the_whole_year(): void
    {
        $response = $this->report()->assertOk()
            ->assertJsonPath('fiscalYear.title', '1405')
            ->assertJsonPath('from', '2026-03-21')
            ->assertJsonPath('to', '2027-03-20')
            ->assertJsonPath('level', 3)
            ->assertJsonPath('balanced', true);

        $rows = $this->byCode($response);

        // technical accounts without any movement are hidden
        $this->assertSame(['1101', '3101', '4101', '5101'], $this->codesOf($rows));

        //                                  opening      period              closing
        $this->assertSame([0, 0, 1500000, 200000, 1300000, 0], $this->numbers($rows['1101']));
        $this->assertSame([0, 0, 0, 1000000, 0, 1000000], $this->numbers($rows['3101']));
        $this->assertSame([0, 0, 0, 500000, 0, 500000], $this->numbers($rows['4101']));
        $this->assertSame([0, 0, 200000, 0, 200000, 0], $this->numbers($rows['5101']));

        $this->assertSame(
            ['openingDebit' => 0, 'openingCredit' => 0, 'periodDebit' => 1700000, 'periodCredit' => 1700000, 'closingDebit' => 1500000, 'closingCredit' => 1500000],
            $response->json('totals'),
        );

        $this->assertSame('Cash', $rows['1101']['name']);
        $this->assertSame(3, $rows['1101']['level']);
    }

    public function test_a_date_range_starts_with_the_opening_balances(): void
    {
        $response = $this->report(['from' => '2026-06-01', 'to' => '2026-12-31'])->assertOk()
            ->assertJsonPath('balanced', true);
        $rows = $this->byCode($response);

        $this->assertSame(['1101', '3101', '4101', '5101'], $this->codesOf($rows));
        $this->assertSame([1000000, 0, 500000, 200000, 1300000, 0], $this->numbers($rows['1101']));
        $this->assertSame([0, 1000000, 0, 0, 0, 1000000], $this->numbers($rows['3101'])); // no movement, but an opening balance
        $this->assertSame([0, 0, 0, 500000, 0, 500000], $this->numbers($rows['4101']));
        $this->assertSame([0, 0, 200000, 0, 200000, 0], $this->numbers($rows['5101']));

        $this->assertSame(1000000, $response->json('totals.openingDebit'));
        $this->assertSame(1000000, $response->json('totals.openingCredit'));

        // an early end date leaves out later movements
        $early = $this->byCode($this->report(['to' => '2026-05-31'])->assertOk());
        $this->assertSame(['1101', '3101'], $this->codesOf($early));
        $this->assertSame([0, 0, 1000000, 0, 1000000, 0], $this->numbers($early['1101']));
    }

    public function test_levels_roll_up_to_the_kol_and_the_group(): void
    {
        $kols = $this->byCode($this->report(['level' => 2])->assertOk()->assertJsonPath('balanced', true));
        $this->assertSame(['11', '31', '41', '51'], $this->codesOf($kols));
        $this->assertSame([0, 0, 1500000, 200000, 1300000, 0], $this->numbers($kols['11']));
        $this->assertSame('Current assets', $kols['11']['name']);
        $this->assertSame(2, $kols['11']['level']);

        $groups = $this->byCode($this->report(['level' => 1])->assertOk()->assertJsonPath('balanced', true));
        $this->assertSame(['1', '3', '4', '5'], $this->codesOf($groups));
        $this->assertSame([0, 0, 0, 1000000, 0, 1000000], $this->numbers($groups['3']));
        $this->assertSame(1, $groups['3']['level']);
    }

    public function test_one_account_and_everything_below_it(): void
    {
        $kol = (int) DB::table('accounts')->where('code', '11')->value('id');

        $response = $this->report(['accountId' => $kol])->assertOk()
            ->assertJsonPath('balanced', null); // a part of the ledger does not have to balance

        $this->assertSame(['1101'], $this->codesOf($this->byCode($response)));
    }

    public function test_hide_zero_can_be_turned_off(): void
    {
        // every movement account is listed now, also the ones that were never used
        $rows = $this->byCode($this->report(['hideZero' => 0])->assertOk()->assertJsonPath('balanced', true));
        $codes = $this->codesOf($rows);

        $this->assertContains('3102', $codes);
        $this->assertContains('3105', $codes);
        $this->assertCount(8, $codes);

        $this->assertSame([0, 0, 0, 0, 0, 0], $this->numbers($rows['3102']));
        $this->assertSame([0, 0, 1500000, 200000, 1300000, 0], $this->numbers($rows['1101'])); // unchanged

        // the same at the level of the kol accounts
        $kols = $this->codesOf($this->byCode($this->report(['level' => 2, 'hideZero' => 0])->assertOk()));
        $this->assertSame(['11', '31', '41', '51'], $kols);
    }

    public function test_detail_accounts(): void
    {
        $customers = $this->customersScenario();

        // without details: one row for the account
        $plain = $this->byCode($this->report()->assertOk()->assertJsonPath('balanced', true));
        $this->assertSame([0, 0, 400000, 0, 400000, 0], $this->numbers($plain['1103']));

        // with details: one row per customer
        $response = $this->report(['withDetails' => 1])->assertOk()->assertJsonPath('balanced', true);
        $rows = collect($response->json('rows'))->where('code', '1103')->values();

        $this->assertCount(2, $rows);
        $this->assertSame(['Customer A', 'Customer B'], $rows->pluck('detailName')->all());
        $this->assertSame([$customers['a'], $customers['b']], $rows->pluck('detailId')->all());
        $this->assertSame([300000, 100000], $rows->pluck('closingDebit')->all());

        // accounts without a detail account have a null detail
        $cash = collect($response->json('rows'))->firstWhere('code', '1101');
        $this->assertNull($cash['detailId']);

        // details only make sense at level 3
        $this->report(['level' => 2, 'withDetails' => 1])->assertStatus(422)->assertJsonValidationErrors('withDetails');
    }

    // -----------------------------------------------------------------
    // Year-end entries
    // -----------------------------------------------------------------

    public function test_closing_entries_are_left_out_unless_asked_for(): void
    {
        $this->closeTheYear($this->adminToken);

        // by default the report looks like before the closing
        $before = $this->report()->assertOk()->assertJsonPath('balanced', true);
        $this->assertSame([0, 0, 1500000, 200000, 1300000, 0], $this->numbers($this->byCode($before)['1101']));
        $this->assertSame(1500000, $before->json('totals.closingDebit'));

        // including them, every account is closed
        $after = $this->report(['includeClosing' => 1])->assertOk()
            ->assertJsonPath('balanced', true)
            ->assertJsonPath('includeClosing', true)
            ->assertJsonPath('totals.closingDebit', 0)
            ->assertJsonPath('totals.closingCredit', 0);

        $rows = $this->byCode($after);
        $this->assertSame([0, 0, 1500000, 1500000, 0, 0], $this->numbers($rows['1101']));
        $this->assertSame([0, 0, 500000, 500000, 0, 0], $this->numbers($rows['4101']));
    }

    public function test_the_next_year_starts_with_the_opening_entry(): void
    {
        $this->closeTheYear($this->adminToken);

        $rows = $this->byCode($this->report([], null, $this->nextYear)->assertOk()->assertJsonPath('balanced', true));

        // the opening entry is dated on the first day, so it is part of the first period
        $this->assertSame([0, 0, 1300000, 0, 1300000, 0], $this->numbers($rows['1101']));
        $this->assertSame([0, 0, 0, 1000000, 0, 1000000], $this->numbers($rows['3101']));
        $this->assertSame([0, 0, 0, 300000, 0, 300000], $this->numbers($rows['3102']));

        // a report that starts after it shows the same amounts as opening balances
        $later = $this->byCode($this->report(['from' => '2027-03-22'], null, $this->nextYear)->assertOk());
        $this->assertSame([1300000, 0, 0, 0, 1300000, 0], $this->numbers($later['1101']));
    }

    // -----------------------------------------------------------------
    // Input and access
    // -----------------------------------------------------------------

    public function test_invalid_requests_are_rejected(): void
    {
        $cases = [
            'an unknown level' => [['level' => 4], 'level'],
            'a start date before the fiscal year' => [['from' => '2020-01-01'], 'from'],
            'an end date after the fiscal year' => [['to' => '2030-01-01'], 'to'],
            'an end date before the start date' => [['from' => '2026-09-01', 'to' => '2026-06-01'], 'to'],
            'a wrong date format' => [['from' => '01-06-2026'], 'from'],
            'an unknown account' => [['accountId' => 999999], 'accountId'],
        ];

        foreach ($cases as $case => [$query, $errorKey]) {
            $response = $this->report($query);

            $response->assertStatus(422);
            $this->assertArrayHasKey($errorKey, $response->json('errors') ?? [], "The case \"{$case}\" was not rejected for \"{$errorKey}\"");
        }

        $this->api('GET', '/api/reports/trial-balance', [], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');
        $this->report([], null, 999999)->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');
    }

    public function test_who_may_read_reports(): void
    {
        $this->api('GET', "/api/reports/trial-balance?fiscalYearId={$this->year}")->assertStatus(401);

        $this->report([], $this->tokenFor($this->makeUser('viewer')))->assertOk();
        $this->report([], $this->tokenFor($this->makeUser('accountant')))->assertOk();

        DB::table('roles')->insert(['name' => 'no_reports', 'permissions' => json_encode(['entries.read'])]);
        $this->report([], $this->tokenFor($this->makeUser('no_reports')))->assertStatus(403);
    }
}
