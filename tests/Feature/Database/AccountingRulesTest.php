<?php

namespace Tests\Feature\Database;

use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The accounting rules live in PostgreSQL (triggers, constraints, functions).
 * These tests check them directly, without going through the HTTP API.
 */
class AccountingRulesTest extends TestCase
{
    /** @var array{fiscalYear:int, group:int, cash:int, bank:int, receivable:int, detail:int} */
    private array $ledger;

    private JournalEntryService $entries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->makeLedger();
        $this->entries = app(JournalEntryService::class);
    }

    private function postEntry(int $entryId): void
    {
        $this->entries->post($entryId, $this->actor->id);
    }

    public function test_posting_gives_gapless_numbers_starting_at_one(): void
    {
        foreach ([1, 2, 3] as $expected) {
            $id = $this->balancedDraft($this->ledger, 1000 * $expected);
            $this->postEntry($id);

            $number = DB::table('journal_entries')->where('id', $id)->value('number');
            $this->assertSame($expected, (int) $number);
        }
    }

    public function test_numbering_restarts_in_each_fiscal_year(): void
    {
        $nextYear = DB::table('fiscal_years')->insertGetId([
            'title' => '1406',
            'start_date' => '2027-03-21',
            'end_date' => '2028-03-20',
        ]);

        $first = $this->balancedDraft($this->ledger);
        $this->postEntry($first);

        $second = $this->draftEntry($this->ledger, [
            ['accountId' => $this->ledger['cash'], 'debit' => 500],
            ['accountId' => $this->ledger['bank'], 'credit' => 500],
        ], '2027-05-01', $nextYear);
        $this->postEntry($second);

        $this->assertSame(1, (int) DB::table('journal_entries')->where('id', $first)->value('number'));
        $this->assertSame(1, (int) DB::table('journal_entries')->where('id', $second)->value('number'));
    }

    public function test_an_unbalanced_entry_cannot_be_posted(): void
    {
        $id = $this->draftEntry($this->ledger, [
            ['accountId' => $this->ledger['cash'], 'debit' => 500],
            ['accountId' => $this->ledger['bank'], 'credit' => 400],
        ]);

        $this->assertDatabaseRejects(fn () => $this->postEntry($id), 'P0001', 'not balanced');

        $this->assertSame('draft', DB::table('journal_entries')->where('id', $id)->value('status'));
    }

    public function test_an_entry_needs_at_least_two_lines(): void
    {
        $id = $this->draftEntry($this->ledger, [
            ['accountId' => $this->ledger['cash'], 'debit' => 500],
        ]);

        $this->assertDatabaseRejects(fn () => $this->postEntry($id), 'P0001', 'at least two lines');
    }

    public function test_posted_entries_cannot_be_changed_or_deleted(): void
    {
        $id = $this->balancedDraft($this->ledger);
        $this->postEntry($id);
        $lineId = DB::table('journal_lines')->where('entry_id', $id)->value('id');

        $this->assertDatabaseRejects(
            fn () => DB::table('journal_entries')->where('id', $id)->update(['description' => 'changed']),
            'P0001',
            'immutable',
        );
        $this->assertDatabaseRejects(
            fn () => DB::table('journal_entries')->where('id', $id)->delete(),
            'P0001',
            'cannot be deleted',
        );
        $this->assertDatabaseRejects(
            fn () => DB::table('journal_lines')->where('id', $lineId)->update(['debit' => 1]),
            'P0001',
            'immutable',
        );
        $this->assertDatabaseRejects(
            fn () => DB::table('journal_lines')->where('id', $lineId)->delete(),
            'P0001',
            'immutable',
        );
        $this->assertDatabaseRejects(
            fn () => DB::table('journal_lines')->insert([
                'entry_id' => $id,
                'line_no' => 99,
                'account_id' => $this->ledger['cash'],
                'debit' => 5,
                'credit' => 0,
            ]),
            'P0001',
            'immutable',
        );
    }

    public function test_a_draft_can_be_deleted_together_with_its_lines(): void
    {
        $id = $this->balancedDraft($this->ledger);

        $this->entries->deleteDraft($id, $this->actor->id);

        $this->assertSame(0, DB::table('journal_entries')->where('id', $id)->count());
        $this->assertSame(0, DB::table('journal_lines')->where('entry_id', $id)->count());
    }

    public function test_posting_outside_the_fiscal_year_is_rejected(): void
    {
        $id = $this->balancedDraft($this->ledger, 1000, '2028-01-01');

        $this->assertDatabaseRejects(fn () => $this->postEntry($id), 'P0001', 'outside the fiscal year');
    }

    public function test_posting_into_a_closed_fiscal_year_is_rejected(): void
    {
        $id = $this->balancedDraft($this->ledger);
        DB::table('fiscal_years')->where('id', $this->ledger['fiscalYear'])->update(['status' => 'closed']);

        $this->assertDatabaseRejects(fn () => $this->postEntry($id), 'P0001', 'closed');
    }

    public function test_lines_on_group_inactive_or_detail_less_accounts_are_rejected(): void
    {
        // A group account (not postable)
        $groupEntry = $this->draftEntry($this->ledger, [
            ['accountId' => $this->ledger['group'], 'debit' => 100],
            ['accountId' => $this->ledger['bank'], 'credit' => 100],
        ]);
        $this->assertDatabaseRejects(fn () => $this->postEntry($groupEntry), 'P0001', 'invalid line');

        // An account that requires a detail account, used without one
        $noDetail = $this->draftEntry($this->ledger, [
            ['accountId' => $this->ledger['receivable'], 'debit' => 100],
            ['accountId' => $this->ledger['cash'], 'credit' => 100],
        ]);
        $this->assertDatabaseRejects(fn () => $this->postEntry($noDetail), 'P0001', 'invalid line');

        // The same entry is fine once the detail account is given
        $withDetail = $this->draftEntry($this->ledger, [
            ['accountId' => $this->ledger['receivable'], 'detailId' => $this->ledger['detail'], 'debit' => 100],
            ['accountId' => $this->ledger['cash'], 'credit' => 100],
        ]);
        $this->postEntry($withDetail);
        $this->assertSame('posted', DB::table('journal_entries')->where('id', $withDetail)->value('status'));

        // An inactive account
        DB::table('accounts')->where('id', $this->ledger['bank'])->update(['is_active' => false]);
        $inactive = $this->balancedDraft($this->ledger);
        $this->assertDatabaseRejects(fn () => $this->postEntry($inactive), 'P0001', 'invalid line');
    }

    public function test_a_line_cannot_have_both_amounts_none_or_a_negative_amount(): void
    {
        $id = $this->balancedDraft($this->ledger);

        $insert = fn (int $debit, int $credit) => fn () => DB::table('journal_lines')->insert([
            'entry_id' => $id,
            'line_no' => 50,
            'account_id' => $this->ledger['cash'],
            'debit' => $debit,
            'credit' => $credit,
        ]);

        $this->assertDatabaseRejects($insert(100, 100), '23514'); // both
        $this->assertDatabaseRejects($insert(0, 0), '23514');     // empty
        $this->assertDatabaseRejects($insert(-5, 0), '23514');    // negative
    }

    public function test_fiscal_years_cannot_overlap(): void
    {
        $this->assertDatabaseRejects(
            fn () => DB::table('fiscal_years')->insert([
                'title' => 'overlap',
                'start_date' => '2027-01-01',
                'end_date' => '2027-12-31',
            ]),
            '23P01',
        );
    }

    public function test_a_failed_save_leaves_no_half_written_entry(): void
    {
        $actorId = $this->actor->id;

        $this->assertDatabaseRejects(
            fn () => $this->entries->createDraft([
                'fiscalYearId' => $this->ledger['fiscalYear'],
                'entryDate' => '2026-10-28',
                'description' => null,
                'lines' => [
                    ['accountId' => $this->ledger['cash'], 'debit' => 100, 'credit' => 0],
                    ['accountId' => 999999, 'debit' => 0, 'credit' => 100], // does not exist
                ],
            ], $actorId),
            '23503',
        );

        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_reversing_swaps_debit_and_credit_and_cancels_the_balances(): void
    {
        $id = $this->balancedDraft($this->ledger, 2500);
        $this->postEntry($id);

        $reversalId = $this->entries->reverse($id, $this->actor->id, null);

        $lines = DB::table('journal_lines')->where('entry_id', $reversalId)->orderBy('line_no')->get();
        $this->assertSame([0, 2500], [(int) $lines[0]->debit, (int) $lines[0]->credit]);
        $this->assertSame([2500, 0], [(int) $lines[1]->debit, (int) $lines[1]->credit]);

        $this->postEntry($reversalId);

        $balances = DB::table('v_trial_balance')
            ->where('fiscal_year_id', $this->ledger['fiscalYear'])
            ->get();

        $this->assertCount(2, $balances);
        foreach ($balances as $row) {
            $this->assertSame(2500, (int) $row->total_debit);
            $this->assertSame(0, (int) $row->balance);
        }
    }

    public function test_an_entry_can_only_be_reversed_once(): void
    {
        $id = $this->balancedDraft($this->ledger);
        $this->postEntry($id);
        $this->entries->reverse($id, $this->actor->id, null);

        $actorId = $this->actor->id;
        $this->assertDatabaseRejects(fn () => $this->entries->reverse($id, $actorId, null), '23505');
    }

    public function test_only_posted_entries_can_be_reversed(): void
    {
        $id = $this->balancedDraft($this->ledger);
        $actorId = $this->actor->id;

        $this->assertDatabaseRejects(fn () => $this->entries->reverse($id, $actorId, null), 'P0001', 'Only posted');
    }

    public function test_drafts_are_not_part_of_the_trial_balance(): void
    {
        $this->balancedDraft($this->ledger);

        $this->assertSame(0, DB::table('v_trial_balance')->count());
    }

    public function test_large_amounts_are_stored_exactly(): void
    {
        $amount = 9_007_199_254_740_991; // the largest integer JavaScript can hold exactly

        $id = $this->balancedDraft($this->ledger, $amount);
        $this->postEntry($id);

        $this->assertSame($amount, (int) DB::table('journal_entries')->where('id', $id)->value('total_debit'));
        $this->assertSame($amount, (int) DB::table('journal_lines')->where('entry_id', $id)->max('debit'));
    }
}
