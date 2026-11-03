<?php

namespace Tests\Feature\Reports;

use App\Support\LedgerIntegrity;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

class ReportTotalsTest extends TestCase
{
    use BuildsReportScenario;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
        $this->buildChart();
        $this->configureSettings();
    }

    public function test_posting_adds_to_the_daily_totals(): void
    {
        $this->postEntry([['cash', 1000, 0], ['capital', 0, 1000]], '2026-06-01');
        $this->postEntry([['cash', 500, 0], ['sales', 0, 500]], '2026-06-01');
        $this->postEntry([['cash', 0, 200], ['rent', 200, 0]], '2026-06-02');

        $cash = DB::table('account_daily_totals')
            ->where('account_id', $this->a['cash'])
            ->orderBy('entry_date')
            ->get();

        $this->assertCount(2, $cash); // two entries on the same day share one row
        $this->assertSame(['2026-06-01', 1500, 0], [$cash[0]->entry_date, (int) $cash[0]->debit, (int) $cash[0]->credit]);
        $this->assertSame(['2026-06-02', 0, 200], [$cash[1]->entry_date, (int) $cash[1]->debit, (int) $cash[1]->credit]);

        foreach ($cash as $row) {
            $this->assertFalse((bool) $row->closing);
            $this->assertSame(0, (int) $row->detail_id);
            $this->assertSame($this->year, (int) $row->fiscal_year_id);
        }
    }

    public function test_drafts_are_not_counted(): void
    {
        $this->makeDraft([['cash', 1000, 0], ['capital', 0, 1000]]);

        $this->assertSame(0, DB::table('account_daily_totals')->count());
    }

    public function test_detail_accounts_have_their_own_rows(): void
    {
        $customers = $this->customersScenario();

        $rows = DB::table('account_daily_totals')
            ->where('account_id', $customers['customers'])
            ->orderBy('detail_id')
            ->get();

        $this->assertSame([$customers['a'], $customers['b']], $rows->pluck('detail_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([300000, 100000], $rows->pluck('debit')->map(fn ($v) => (int) $v)->all());
    }

    public function test_year_end_entries_and_their_reversals_are_flagged_as_closing(): void
    {
        $this->reportScenario();
        $this->closeTheYear($this->adminToken);

        $closingRows = DB::table('account_daily_totals')->where('closing', true)->get();
        $this->assertNotEmpty($closingRows);
        foreach ($closingRows as $row) {
            $this->assertSame($this->year, (int) $row->fiscal_year_id);
            $this->assertSame('2027-03-20', $row->entry_date);
        }

        // the opening entry of the next year is not a closing entry
        $this->assertSame(0, DB::table('account_daily_totals')->where('fiscal_year_id', $this->nextYear)->where('closing', true)->count());

        $this->api('POST', "/api/fiscal-years/{$this->year}/year-end/reverse", ['reason' => 'Correcting a missing invoice'], $this->adminToken)
            ->assertOk();

        // a closing entry and its reversal land in the same rows and cancel each other out
        foreach (DB::table('account_daily_totals')->where('closing', true)->get() as $row) {
            $this->assertSame((int) $row->debit, (int) $row->credit, "Account {$row->account_id} does not cancel out");
        }
    }

    public function test_the_integrity_check_passes_and_detects_tampering(): void
    {
        $this->reportScenario();
        $this->customersScenario();
        $this->closeTheYear($this->adminToken);

        $result = LedgerIntegrity::check();
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertGreaterThan(0, $result['postedEntries']);
        $this->artisan('accounting:verify-ledger')->assertExitCode(0);

        // someone edits the stored totals by hand
        DB::table('account_daily_totals')
            ->where('account_id', $this->a['cash'])
            ->update(['debit' => DB::raw('debit + 1')]);

        $broken = LedgerIntegrity::check();
        $this->assertFalse($broken['ok']);
        $this->assertGreaterThan(0, $broken['totalsMismatches']);
        $this->assertNotEmpty($broken['examples']);
        $this->artisan('accounting:verify-ledger')->assertExitCode(1);
    }
}
