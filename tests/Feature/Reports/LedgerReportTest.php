<?php

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

class LedgerReportTest extends TestCase
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
    private function ledger(array $query, ?string $token = null): TestResponse
    {
        return $this->api(
            'GET',
            '/api/reports/ledger?'.http_build_query(['fiscalYearId' => $this->year] + $query),
            [],
            $token ?? $this->adminToken,
        );
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array{0: string, 1: int, 2: int, 3: int}> [date, debit, credit, balance]
     */
    private function summary(array $response): array
    {
        return array_map(fn (array $i) => [$i['date'], $i['debit'], $i['credit'], $i['balance']], $response['items']);
    }

    private function accountId(string $code): int
    {
        return (int) DB::table('accounts')->where('code', $code)->value('id');
    }

    // -----------------------------------------------------------------
    // The numbers
    // -----------------------------------------------------------------

    public function test_the_ledger_of_a_movement_account(): void
    {
        $response = $this->ledger(['accountId' => $this->a['cash']])->assertOk()
            ->assertJsonPath('account.code', '1101')
            ->assertJsonPath('account.level', 3)
            ->assertJsonPath('openingBalance', 0)
            ->assertJsonPath('totals.periodDebit', 1500000)
            ->assertJsonPath('totals.periodCredit', 200000)
            ->assertJsonPath('totals.closingBalance', 1300000)
            ->assertJsonPath('nextCursor', null);

        $this->assertSame(
            [['2026-04-01', 1000000, 0, 1000000], ['2026-06-01', 500000, 0, 1500000], ['2026-09-01', 0, 200000, 1300000]],
            $this->summary($response->json()),
        );
        $this->assertSame([1, 2, 3], array_column($response->json('items'), 'number'));
        $this->assertSame('1101', $response->json('items.0.accountCode'));
    }

    public function test_a_date_range_starts_with_the_opening_balance(): void
    {
        $response = $this->ledger(['accountId' => $this->a['cash'], 'from' => '2026-06-01'])->assertOk()
            ->assertJsonPath('openingBalance', 1000000)
            ->assertJsonPath('totals.closingBalance', 1300000);

        $this->assertSame(
            [['2026-06-01', 500000, 0, 1500000], ['2026-09-01', 0, 200000, 1300000]],
            $this->summary($response->json()),
        );

        // a range without movements still shows the balance
        $quiet = $this->ledger(['accountId' => $this->a['cash'], 'from' => '2026-10-01'])->assertOk()
            ->assertJsonPath('openingBalance', 1300000)
            ->assertJsonPath('totals.periodDebit', 0)
            ->assertJsonPath('totals.closingBalance', 1300000);
        $this->assertSame([], $quiet->json('items'));
    }

    public function test_a_kol_and_a_group_include_everything_below_them(): void
    {
        $kol = $this->ledger(['accountId' => $this->accountId('11')])->assertOk()
            ->assertJsonPath('account.level', 2);
        $this->assertSame(
            [['2026-04-01', 1000000, 0, 1000000], ['2026-06-01', 500000, 0, 1500000], ['2026-09-01', 0, 200000, 1300000]],
            $this->summary($kol->json()),
        );

        $equity = $this->ledger(['accountId' => $this->accountId('3')])->assertOk()
            ->assertJsonPath('account.level', 1)
            ->assertJsonPath('totals.closingBalance', -1000000);
        $this->assertSame([['2026-04-01', 0, 1000000, -1000000]], $this->summary($equity->json()));
    }

    public function test_entries_of_the_same_day_are_ordered_by_number(): void
    {
        $this->postEntry([['cash', 10, 0], ['capital', 0, 10]], '2026-10-05');
        $this->postEntry([['cash', 20, 0], ['sales', 0, 20]], '2026-10-05');

        $response = $this->ledger(['accountId' => $this->a['cash'], 'from' => '2026-10-05'])->assertOk();

        $this->assertSame([4, 5], array_column($response->json('items'), 'number'));
        $this->assertSame([1300010, 1300030], array_column($response->json('items'), 'balance'));
    }

    // -----------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------

    public function test_pages_continue_where_the_previous_one_ended(): void
    {
        $first = $this->ledger(['accountId' => $this->a['cash'], 'pageSize' => 2])->assertOk();

        $this->assertSame(
            [['2026-04-01', 1000000, 0, 1000000], ['2026-06-01', 500000, 0, 1500000]],
            $this->summary($first->json()),
        );
        $cursor = $first->json('nextCursor');
        $this->assertIsString($cursor);

        // the totals describe the whole range on every page
        $first->assertJsonPath('totals.closingBalance', 1300000);

        $second = $this->ledger(['accountId' => $this->a['cash'], 'pageSize' => 2, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('nextCursor', null)
            ->assertJsonPath('totals.closingBalance', 1300000);

        $this->assertSame([['2026-09-01', 0, 200000, 1300000]], $this->summary($second->json()));
        $this->assertSame(3, $second->json('items.0.number'));

        // a page of exactly the remaining size has no next page
        $this->ledger(['accountId' => $this->a['cash'], 'pageSize' => 3])->assertOk()->assertJsonPath('nextCursor', null);
    }

    public function test_a_changed_cursor_is_refused(): void
    {
        $cursor = $this->ledger(['accountId' => $this->a['cash'], 'pageSize' => 1])->json('nextCursor');
        [$payload, $signature] = explode('.', $cursor);

        // a forged running balance
        $forged = rtrim(strtr(base64_encode(json_encode(['d' => '2026-04-01', 'n' => 1, 'l' => 1, 'b' => 999999999])), '+/', '-_'), '=');

        foreach ([$forged.'.'.$signature, $payload.'.'.str_repeat('0', 64), $payload, 'garbage.garbage'] as $bad) {
            $this->ledger(['accountId' => $this->a['cash'], 'cursor' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('cursor');
        }
    }

    // -----------------------------------------------------------------
    // Detail accounts and closing entries
    // -----------------------------------------------------------------

    public function test_the_ledger_of_one_detail_account(): void
    {
        $customers = $this->customersScenario();
        $account = $customers['customers'];

        $all = $this->ledger(['accountId' => $account])->assertOk();
        $this->assertSame([300000, 400000], array_column($all->json('items'), 'balance'));
        $this->assertSame(['Customer A', 'Customer B'], array_column($all->json('items'), 'detailName'));

        $only = $this->ledger(['accountId' => $account, 'detailId' => $customers['b']])->assertOk()
            ->assertJsonPath('detailId', $customers['b'])
            ->assertJsonPath('totals.closingBalance', 100000);
        $this->assertSame([['2026-07-02', 100000, 0, 100000]], $this->summary($only->json()));
    }

    public function test_closing_entries_are_left_out_unless_asked_for(): void
    {
        $this->closeTheYear($this->adminToken);

        $default = $this->ledger(['accountId' => $this->a['cash']])->assertOk()
            ->assertJsonPath('totals.closingBalance', 1300000);
        $this->assertCount(3, $default->json('items'));

        $withClosing = $this->ledger(['accountId' => $this->a['cash'], 'includeClosing' => 1])->assertOk()
            ->assertJsonPath('includeClosing', true)
            ->assertJsonPath('totals.closingBalance', 0);

        $this->assertCount(4, $withClosing->json('items'));
        $last = $withClosing->json('items.3');
        $this->assertSame(['2027-03-20', 0, 1300000, 0], [$last['date'], $last['debit'], $last['credit'], $last['balance']]);
    }

    // -----------------------------------------------------------------
    // Agreement with the trial balance
    // -----------------------------------------------------------------

    public function test_every_ledger_agrees_with_the_trial_balance(): void
    {
        $this->customersScenario();

        $rows = $this->api('GET', "/api/reports/trial-balance?fiscalYearId={$this->year}", [], $this->adminToken)
            ->assertOk()->json('rows');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $ledger = $this->ledger(['accountId' => $row['accountId']])->assertOk();

            $this->assertSame(
                $row['closingDebit'] - $row['closingCredit'],
                $ledger->json('totals.closingBalance'),
                "The ledger of {$row['code']} does not agree with the trial balance",
            );

            // and the last running balance is the closing balance
            $items = $ledger->json('items');
            $this->assertSame($ledger->json('totals.closingBalance'), end($items)['balance']);
        }
    }

    // -----------------------------------------------------------------
    // Input and access
    // -----------------------------------------------------------------

    public function test_invalid_requests_are_rejected(): void
    {
        $cash = $this->a['cash'];

        $cases = [
            'a missing account' => [[], 'accountId'],
            'an unknown account' => [['accountId' => 999999], 'accountId'],
            'an unknown detail account' => [['accountId' => $cash, 'detailId' => 999999], 'detailId'],
            'a page that is too big' => [['accountId' => $cash, 'pageSize' => 201], 'pageSize'],
            'an empty page' => [['accountId' => $cash, 'pageSize' => 0], 'pageSize'],
            'a start date outside the year' => [['accountId' => $cash, 'from' => '2020-01-01'], 'from'],
            'an end date before the start' => [['accountId' => $cash, 'from' => '2026-09-01', 'to' => '2026-06-01'], 'to'],
        ];

        foreach ($cases as $case => [$query, $errorKey]) {
            $response = $this->ledger($query);

            $response->assertStatus(422);
            $this->assertArrayHasKey($errorKey, $response->json('errors') ?? [], "The case \"{$case}\" was not rejected for \"{$errorKey}\"");
        }
    }

    public function test_who_may_read_the_ledger(): void
    {
        $this->api('GET', "/api/reports/ledger?fiscalYearId={$this->year}&accountId={$this->a['cash']}")->assertStatus(401);

        $this->ledger(['accountId' => $this->a['cash']], $this->tokenFor($this->makeUser('viewer')))->assertOk();

        DB::table('roles')->insert(['name' => 'no_reports', 'permissions' => json_encode(['entries.read'])]);
        $this->ledger(['accountId' => $this->a['cash']], $this->tokenFor($this->makeUser('no_reports')))->assertStatus(403);
    }
}
