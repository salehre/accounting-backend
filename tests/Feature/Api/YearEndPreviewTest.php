<?php

namespace Tests\Feature\Api;

use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class YearEndPreviewTest extends TestCase
{
    private string $adminToken;

    /** @var array<string, int> */
    private array $a = [];

    private int $year;

    private int $nextYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
        $this->buildChart();
    }

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
            'expenses' => $expenses,
            'expensesKol' => $expensesKol,
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
    private function draftEntry(array $lines, string $date = '2026-06-01'): int
    {
        return app(JournalEntryService::class)->createDraft([
            'fiscalYearId' => $this->year,
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
    private function postEntry(array $lines, string $date = '2026-06-01'): void
    {
        app(JournalEntryService::class)->post($this->draftEntry($lines, $date), $this->actor->id);
    }

    private function preview(?int $year = null, ?string $token = null): TestResponse
    {
        return $this->api('GET', '/api/fiscal-years/'.($year ?? $this->year).'/year-end', [], $token ?? $this->adminToken);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<int, array{0: string, 1: int, 2: int}>
     */
    private function lines(array $entry): array
    {
        return array_map(fn (array $l) => [$l['accountCode'], $l['debit'], $l['credit']], $entry['lines']);
    }

    /** Capital 1,000,000 + sales 500,000 - rent 200,000: a profit of 300,000. */
    private function profitScenario(): void
    {
        $this->postEntry([['cash', 1000000, 0], ['capital', 0, 1000000]]);
        $this->postEntry([['cash', 500000, 0], ['sales', 0, 500000]]);
        $this->postEntry([['rent', 200000, 0], ['cash', 0, 200000]]);
    }

    /** @return array<string, array<string, mixed>> */
    private function planOf(TestResponse $response): array
    {
        return collect($response->json('entries'))->keyBy('type')->all();
    }

    // -----------------------------------------------------------------
    // The plan
    // -----------------------------------------------------------------

    public function test_the_plan_for_a_profitable_year(): void
    {
        $this->configureSettings();
        $this->profitScenario();

        $response = $this->preview()->assertOk()
            ->assertJsonPath('canGenerate', true)
            ->assertJsonPath('blockers', [])
            ->assertJsonPath('summary.netProfit', 300000)
            ->assertJsonPath('summary.temporaryAccounts', 2)
            ->assertJsonPath('summary.permanentAccounts', 3)
            ->assertJsonPath('summary.nextFiscalYearId', $this->nextYear);

        $entries = $this->planOf($response);
        $this->assertSame(['temporary', 'profit', 'closing', 'opening'], array_keys($entries));

        $this->assertSame(
            [['4101', 500000, 0], ['5101', 0, 200000], ['3103', 200000, 0], ['3103', 0, 500000]],
            $this->lines($entries['temporary']),
        );
        $this->assertSame(
            [['3103', 300000, 0], ['3102', 0, 300000]],
            $this->lines($entries['profit']),
        );
        $this->assertSame(
            [['1101', 0, 1300000], ['3101', 1000000, 0], ['3102', 300000, 0], ['3104', 1300000, 0], ['3104', 0, 1300000]],
            $this->lines($entries['closing']),
        );
        $this->assertSame(
            [['1101', 1300000, 0], ['3101', 0, 1000000], ['3102', 0, 300000], ['3105', 1300000, 0], ['3105', 0, 1300000]],
            $this->lines($entries['opening']),
        );

        // dates and years
        foreach (['temporary', 'profit', 'closing'] as $type) {
            $this->assertSame('2027-03-20', $entries[$type]['entryDate']);
            $this->assertSame($this->year, $entries[$type]['fiscalYearId']);
        }
        $this->assertSame('2027-03-21', $entries['opening']['entryDate']);
        $this->assertSame($this->nextYear, $entries['opening']['fiscalYearId']);

        // every entry balances
        foreach ($entries as $type => $entry) {
            $this->assertSame($entry['totalDebit'], $entry['totalCredit'], "The \"{$type}\" entry does not balance");
        }
    }

    public function test_the_plan_for_a_year_with_a_loss(): void
    {
        $this->configureSettings();
        $this->postEntry([['cash', 1000000, 0], ['capital', 0, 1000000]]);
        $this->postEntry([['cash', 500000, 0], ['sales', 0, 500000]]);
        $this->postEntry([['rent', 800000, 0], ['cash', 0, 800000]]);

        $response = $this->preview()->assertOk()
            ->assertJsonPath('canGenerate', true)
            ->assertJsonPath('summary.netProfit', -300000);
        $entries = $this->planOf($response);

        $this->assertSame(
            [['4101', 500000, 0], ['5101', 0, 800000], ['3103', 800000, 0], ['3103', 0, 500000]],
            $this->lines($entries['temporary']),
        );
        $this->assertSame(
            [['3103', 0, 300000], ['3102', 300000, 0]],
            $this->lines($entries['profit']),
        );
        $this->assertSame(
            [['1101', 0, 700000], ['3101', 1000000, 0], ['3102', 0, 300000], ['3104', 1000000, 0], ['3104', 0, 1000000]],
            $this->lines($entries['closing']),
        );

        foreach ($entries as $type => $entry) {
            $this->assertSame($entry['totalDebit'], $entry['totalCredit'], "The \"{$type}\" entry does not balance");
        }
    }

    public function test_no_profit_entry_when_the_result_is_zero(): void
    {
        $this->configureSettings();
        $this->postEntry([['cash', 500000, 0], ['sales', 0, 500000]]);
        $this->postEntry([['rent', 500000, 0], ['cash', 0, 500000]]);

        $entries = $this->planOf($this->preview()->assertOk()->assertJsonPath('summary.netProfit', 0));

        $this->assertSame(['temporary'], array_keys($entries)); // nothing is left in the permanent accounts either
    }

    public function test_accounts_with_a_detail_account_are_closed_per_detail_account(): void
    {
        $this->configureSettings();
        $costCenter = $this->makeAccount('5102', 'Marketing', 3, 'debit', $this->a['expensesKol'], true, true);
        $detail = DB::table('detail_accounts')->insertGetId(['code' => 'CC1', 'name' => 'Marketing dept', 'kind' => 'cost_center']);

        $draft = app(JournalEntryService::class)->createDraft([
            'fiscalYearId' => $this->year,
            'entryDate' => '2026-06-01',
            'description' => null,
            'lines' => [
                ['accountId' => $costCenter, 'detailId' => $detail, 'debit' => 50000, 'credit' => 0],
                ['accountId' => $this->a['cash'], 'debit' => 0, 'credit' => 50000],
            ],
        ], $this->actor->id);
        app(JournalEntryService::class)->post($draft, $this->actor->id);

        $temporary = $this->planOf($this->preview()->assertOk())['temporary'];
        $first = $temporary['lines'][0];

        $this->assertSame('5102', $first['accountCode']);
        $this->assertSame($detail, $first['detailId']);
        $this->assertSame('Marketing dept', $first['detailName']);
        $this->assertSame([0, 50000], [$first['debit'], $first['credit']]);
    }

    public function test_only_posted_entries_of_this_year_count(): void
    {
        $this->configureSettings();
        $this->profitScenario();

        // an entry of another year does not change this year's plan
        $other = app(JournalEntryService::class)->createDraft([
            'fiscalYearId' => $this->nextYear,
            'entryDate' => '2027-06-01',
            'description' => null,
            'lines' => [
                ['accountId' => $this->a['cash'], 'debit' => 999, 'credit' => 0],
                ['accountId' => $this->a['sales'], 'debit' => 0, 'credit' => 999],
            ],
        ], $this->actor->id);
        app(JournalEntryService::class)->post($other, $this->actor->id);

        $this->preview()->assertOk()->assertJsonPath('summary.netProfit', 300000);
    }

    // -----------------------------------------------------------------
    // Blockers and warnings
    // -----------------------------------------------------------------

    public function test_missing_settings_block_the_plan(): void
    {
        $this->profitScenario();

        $response = $this->preview()->assertOk()
            ->assertJsonPath('canGenerate', false)
            ->assertJsonPath('blockers.0.code', 'settings_missing')
            ->assertJsonPath('entries', []);

        $this->assertCount(4, $response->json('blockers.0.fields'));
    }

    public function test_unclassified_groups_with_balances_block_the_plan(): void
    {
        $this->configureSettings();
        $this->profitScenario();
        DB::table('account_group_categories')->where('account_id', $this->a['expenses'])->delete();

        $response = $this->preview()->assertOk()
            ->assertJsonPath('canGenerate', false)
            ->assertJsonPath('entries', []);

        $codes = array_column($response->json('blockers'), 'code');
        $this->assertContains('unclassified_accounts', $codes);

        $blocker = collect($response->json('blockers'))->firstWhere('code', 'unclassified_accounts');
        $this->assertSame(['5'], array_column($blocker['groups'], 'code'));
    }

    public function test_drafts_and_closed_years_block_generation(): void
    {
        $this->configureSettings();
        $this->profitScenario();
        $this->draftEntry([['cash', 100, 0], ['capital', 0, 100]]);

        $codes = fn () => array_column($this->preview()->assertOk()->json('blockers'), 'code');

        $this->assertSame(['draft_entries'], $codes());

        DB::table('journal_entries')->where('status', 'draft')->delete();
        $this->assertSame([], $codes());

        DB::table('fiscal_years')->where('id', $this->year)->update(['status' => 'closed']);
        $this->assertSame(['year_closed'], $codes());
    }

    public function test_the_next_fiscal_year_is_required_and_must_be_open(): void
    {
        $this->configureSettings();
        $this->profitScenario();

        DB::table('fiscal_years')->where('id', $this->nextYear)->update(['status' => 'closed']);
        $closed = $this->preview()->assertOk();
        $this->assertSame(['next_year_closed'], array_column($closed->json('blockers'), 'code'));

        DB::table('fiscal_years')->where('id', $this->nextYear)->delete();
        $missing = $this->preview()->assertOk();
        $this->assertSame(['next_year_missing'], array_column($missing->json('blockers'), 'code'));

        // the entries of this year are still planned, only the opening entry is missing
        $this->assertSame(['temporary', 'profit', 'closing'], array_keys($this->planOf($missing)));
    }

    public function test_a_gap_before_the_next_year_is_a_warning(): void
    {
        $this->configureSettings();
        $this->profitScenario();
        DB::table('fiscal_years')->where('id', $this->nextYear)->update(['start_date' => '2027-04-01']);

        $response = $this->preview()->assertOk()->assertJsonPath('canGenerate', true);

        $this->assertSame(['gap_before_next_year'], array_column($response->json('warnings'), 'code'));
        $this->assertSame('2027-04-01', $this->planOf($response)['opening']['entryDate']);
    }

    public function test_technical_accounts_must_have_a_zero_balance(): void
    {
        $this->configureSettings();
        $this->profitScenario();
        $this->postEntry([['cash', 100, 0], ['profitLoss', 0, 100]]);

        $response = $this->preview()->assertOk()->assertJsonPath('canGenerate', false);
        $blocker = collect($response->json('blockers'))->firstWhere('code', 'technical_accounts_not_zero');

        $this->assertNotNull($blocker);
        $this->assertSame('3103', $blocker['accounts'][0]['code']);
        $this->assertSame(-100, $blocker['accounts'][0]['balance']);
    }

    public function test_a_disabled_technical_account_blocks_the_plan(): void
    {
        $this->configureSettings();
        $this->profitScenario();
        DB::table('accounts')->where('id', $this->a['closing'])->update(['is_active' => false]);

        $response = $this->preview()->assertOk()->assertJsonPath('canGenerate', false);

        $this->assertContains('settings_invalid', array_column($response->json('blockers'), 'code'));
    }

    public function test_a_year_without_entries_has_nothing_to_close(): void
    {
        $this->configureSettings();

        $response = $this->preview()->assertOk()
            ->assertJsonPath('canGenerate', false)
            ->assertJsonPath('entries', []);

        $this->assertSame(['no_entries'], array_column($response->json('warnings'), 'code'));
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_who_may_see_the_preview(): void
    {
        $this->api('GET', "/api/fiscal-years/{$this->year}/year-end")->assertStatus(401);

        $this->preview(null, $this->tokenFor($this->makeUser('viewer')))->assertStatus(403);
        $this->preview(null, $this->tokenFor($this->makeUser('accountant')))->assertOk();

        $this->preview(999999)->assertStatus(404);
    }

    public function test_the_preview_never_changes_anything(): void
    {
        $this->configureSettings();
        $this->profitScenario();
        $before = DB::table('journal_entries')->count();

        $this->preview()->assertOk();
        $this->preview()->assertOk();

        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('journal_entries')->where('status', 'draft')->count());
    }
}
