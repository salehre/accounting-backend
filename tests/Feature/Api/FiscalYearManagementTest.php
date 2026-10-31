<?php


namespace Tests\Feature\Api;

use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FiscalYearManagementTest extends TestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
    }

    /** @param array<string, mixed> $payload */
    private function createYear(array $payload, ?string $token = null): TestResponse
    {
        return $this->api('POST', '/api/fiscal-years', $payload, $token ?? $this->adminToken);
    }

    /** @return array<string, string> */
    private function year1405(): array
    {
        return ['title' => '1405', 'startDate' => '2026-03-21', 'endDate' => '2027-03-20'];
    }

    // -----------------------------------------------------------------
    // Who may do what
    // -----------------------------------------------------------------

    public function test_reading_managing_and_closing_are_separate_permissions(): void
    {
        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $accountant = $this->tokenFor($this->makeUser('accountant'));

        $all = [
            ['GET', '/api/fiscal-years'],
            ['POST', '/api/fiscal-years'],
            ['PATCH', '/api/fiscal-years/1'],
            ['DELETE', '/api/fiscal-years/1'],
            ['GET', '/api/fiscal-years/1/close-check'],
            ['POST', '/api/fiscal-years/1/close'],
            ['POST', '/api/fiscal-years/1/reopen'],
        ];

        foreach ($all as [$method, $uri]) {
            $this->api($method, $uri)->assertStatus(401);
        }

        // a viewer may only read
        $this->api('GET', '/api/fiscal-years', [], $viewer)->assertOk();
        foreach (array_slice($all, 1) as [$method, $uri]) {
            $this->api($method, $uri, [], $viewer)->assertStatus(403);
        }

        // an accountant may create and edit, but not close or reopen
        $id = $this->createYear($this->year1405(), $accountant)->assertStatus(201)->json('id');
        $this->api('PATCH', "/api/fiscal-years/{$id}", ['title' => '1405 edited'], $accountant)->assertOk();

        foreach ([
                     ['GET', "/api/fiscal-years/{$id}/close-check"],
                     ['POST', "/api/fiscal-years/{$id}/close"],
                     ['POST', "/api/fiscal-years/{$id}/reopen"],
                 ] as [$method, $uri]) {
            $this->api($method, $uri, [], $accountant)->assertStatus(403);
        }

        $this->api('DELETE', "/api/fiscal-years/{$id}", [], $accountant)->assertOk();
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_creating_and_listing_fiscal_years(): void
    {
        $this->createYear($this->year1405())
            ->assertStatus(201)
            ->assertJsonPath('title', '1405')
            ->assertJsonPath('startDate', '2026-03-21')
            ->assertJsonPath('endDate', '2027-03-20')
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('postedEntries', 0)
            ->assertJsonPath('draftEntries', 0)
            ->assertJsonPath('hasEntries', false)
            ->assertJsonPath('warnings', []);

        // the next year starts the day after: no warning
        $this->createYear(['title' => '1406', 'startDate' => '2027-03-21', 'endDate' => '2028-03-20'])
            ->assertStatus(201)
            ->assertJsonPath('warnings', []);

        $list = $this->api('GET', '/api/fiscal-years', [], $this->adminToken)->assertOk()->json();
        $this->assertSame(['1406', '1405'], array_column($list, 'title')); // newest first
    }

    public function test_invalid_fiscal_years_are_rejected(): void
    {
        $this->createYear($this->year1405())->assertStatus(201);

        $invalid = [
            'end before start' => [['startDate' => '2028-03-21', 'endDate' => '2028-03-01'], 'endDate'],
            'end equal to start' => [['startDate' => '2028-03-21', 'endDate' => '2028-03-21'], 'endDate'],
            'wrong date format' => [['startDate' => '21-03-2028', 'endDate' => '2029-03-20'], 'startDate'],
            'impossible date' => [['startDate' => '2028-02-30', 'endDate' => '2029-03-20'], 'startDate'],
            'longer than 730 days' => [['startDate' => '2028-03-21', 'endDate' => '2031-03-20'], 'endDate'],
            'overlapping an existing year' => [['startDate' => '2026-12-01', 'endDate' => '2027-11-30'], 'startDate'],
            'missing title' => [['title' => '', 'startDate' => '2028-03-21', 'endDate' => '2029-03-20'], 'title'],
        ];

        foreach ($invalid as $case => [$override, $errorKey]) {
            $response = $this->createYear($override + ['title' => 'Test']);

            $response->assertStatus(422);
            $this->assertArrayHasKey(
                $errorKey,
                $response->json('errors') ?? [],
                "The case \"{$case}\" was not rejected for \"{$errorKey}\"",
            );
        }

        $this->assertSame(1, DB::table('fiscal_years')->count()); // nothing else was saved
    }

    public function test_gaps_between_years_produce_a_warning(): void
    {
        $this->createYear($this->year1405())->assertStatus(201);

        // 11 days (21 to 31 March 2027) belong to no fiscal year
        $after = $this->createYear(['title' => '1406', 'startDate' => '2027-04-01', 'endDate' => '2028-03-31'])
            ->assertStatus(201);
        $this->assertCount(1, $after->json('warnings'));
        $this->assertStringContainsString('gap of 11 day(s)', $after->json('warnings.0'));

        // a year added before an existing one, leaving a gap
        $before = $this->createYear(['title' => '1403', 'startDate' => '2024-03-20', 'endDate' => '2025-03-15'])
            ->assertStatus(201);
        $this->assertCount(1, $before->json('warnings'));
        $this->assertStringContainsString('before fiscal year "1405"', $before->json('warnings.0'));
    }

    // -----------------------------------------------------------------
    // Editing and deleting
    // -----------------------------------------------------------------

    public function test_updating_an_empty_fiscal_year(): void
    {
        $id = $this->createYear($this->year1405())->assertStatus(201)->json('id');
        $url = "/api/fiscal-years/{$id}";

        $this->api('PATCH', $url, ['title' => 'Renamed', 'endDate' => '2027-03-31'], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('title', 'Renamed')
            ->assertJsonPath('endDate', '2027-03-31');

        $this->api('PATCH', $url, [], $this->adminToken)->assertStatus(422);
        $this->api('PATCH', $url, ['startDate' => '2028-01-01'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('endDate');
        $this->api('PATCH', '/api/fiscal-years/999999', ['title' => 'x'], $this->adminToken)->assertStatus(404);

        // the new dates may not overlap another year
        $this->createYear(['title' => '1406', 'startDate' => '2027-04-01', 'endDate' => '2028-03-31'])->assertStatus(201);
        $this->api('PATCH', $url, ['endDate' => '2027-06-01'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('startDate');
    }

    public function test_dates_are_locked_once_a_fiscal_year_has_entries(): void
    {
        $ledger = $this->makeLedger();
        $this->balancedDraft($ledger);
        $url = "/api/fiscal-years/{$ledger['fiscalYear']}";

        $this->api('PATCH', $url, ['endDate' => '2027-04-30'], $this->adminToken)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dates can no longer be changed: this fiscal year already has entries.');

        // the title can still change
        $this->api('PATCH', $url, ['title' => '1405 - main'], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('title', '1405 - main')
            ->assertJsonPath('hasEntries', true)
            ->assertJsonPath('draftEntries', 1);
    }

    public function test_deleting_fiscal_years(): void
    {
        $empty = $this->createYear(['title' => '2030', 'startDate' => '2030-03-21', 'endDate' => '2031-03-20'])
            ->assertStatus(201)->json('id');
        $this->api('DELETE', "/api/fiscal-years/{$empty}", [], $this->adminToken)->assertOk()->assertJsonPath('ok', true);
        $this->api('DELETE', "/api/fiscal-years/{$empty}", [], $this->adminToken)->assertStatus(404);

        // with entries
        $ledger = $this->makeLedger();
        $this->balancedDraft($ledger);
        $this->api('DELETE', "/api/fiscal-years/{$ledger['fiscalYear']}", [], $this->adminToken)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This fiscal year has entries and cannot be deleted.');

        // closed
        $closed = $this->createYear(['title' => '2040', 'startDate' => '2040-03-21', 'endDate' => '2041-03-20'])
            ->assertStatus(201)->json('id');
        $this->api('POST', "/api/fiscal-years/{$closed}/close", [], $this->adminToken)->assertOk();
        $this->api('DELETE', "/api/fiscal-years/{$closed}", [], $this->adminToken)
            ->assertStatus(409)
            ->assertJsonPath('message', 'A closed fiscal year cannot be deleted.');
    }

    // -----------------------------------------------------------------
    // Closing
    // -----------------------------------------------------------------

    public function test_the_close_check_explains_what_blocks_closing(): void
    {
        $ledger = $this->makeLedger();
        $id = $ledger['fiscalYear'];
        $draft = $this->balancedDraft($ledger, 1000);
        $url = "/api/fiscal-years/{$id}/close-check";

        $this->api('GET', $url, [], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('canClose', false)
            ->assertJsonPath('blockers.0.code', 'draft_entries')
            ->assertJsonPath('blockers.0.count', 1)
            ->assertJsonPath('blockers.0.entries.0.id', $draft)
            ->assertJsonPath('summary.draftEntries', 1);

        app(JournalEntryService::class)->post($draft, $this->actor->id);

        $ready = $this->api('GET', $url, [], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('canClose', true)
            ->assertJsonPath('blockers', [])
            ->assertJsonPath('summary.postedEntries', 1)
            ->assertJsonPath('summary.lastEntryNumber', 1)
            ->assertJsonPath('summary.totalDebit', 1000)
            ->assertJsonPath('summary.totalCredit', 1000);
        $this->assertSame([], $ready->json('warnings'));

        // an earlier year that is still open is a warning, not a blocker
        $this->createYear(['title' => '1404', 'startDate' => '2025-03-21', 'endDate' => '2026-03-20'])->assertStatus(201);
        $warnings = $this->api('GET', $url, [], $this->adminToken)->assertOk()->json('warnings');
        $this->assertSame(['earlier_year_open'], array_column($warnings, 'code'));

        // a year without any entries gets a warning too
        $empty = $this->createYear(['title' => '1410', 'startDate' => '2031-03-21', 'endDate' => '2032-03-20'])
            ->assertStatus(201)->json('id');
        $emptyWarnings = $this->api('GET', "/api/fiscal-years/{$empty}/close-check", [], $this->adminToken)
            ->assertOk()->assertJsonPath('canClose', true)->json('warnings');
        $this->assertContains('no_entries', array_column($emptyWarnings, 'code'));

        $this->api('GET', '/api/fiscal-years/999999/close-check', [], $this->adminToken)->assertStatus(404);
    }

    public function test_closing_a_fiscal_year(): void
    {
        $ledger = $this->makeLedger();
        $id = $ledger['fiscalYear'];
        $draft = $this->balancedDraft($ledger);

        // a draft blocks closing, and the year stays open
        $this->api('POST', "/api/fiscal-years/{$id}/close", [], $this->adminToken)
            ->assertStatus(422)
            ->assertJsonPath('message', 'The fiscal year cannot be closed yet.')
            ->assertJsonPath('blockers.0.code', 'draft_entries');
        $this->assertSame('open', DB::table('fiscal_years')->where('id', $id)->value('status'));

        app(JournalEntryService::class)->post($draft, $this->actor->id);

        $this->api('POST', "/api/fiscal-years/{$id}/close", [], $this->adminToken)
            ->assertOk()->assertJsonPath('status', 'closed');

        $this->api('POST', "/api/fiscal-years/{$id}/close", [], $this->adminToken)
            ->assertStatus(409)->assertJsonPath('message', 'This fiscal year is already closed.');
        $this->api('GET', "/api/fiscal-years/{$id}/close-check", [], $this->adminToken)
            ->assertJsonPath('canClose', false)->assertJsonPath('blockers.0.code', 'already_closed');

        // dates of a closed year cannot change
        $this->api('PATCH', "/api/fiscal-years/{$id}", ['endDate' => '2027-04-01'], $this->adminToken)
            ->assertStatus(422);

        // the entries of a closed year stay readable
        $this->api('GET', "/api/entries?fiscalYearId={$id}", [], $this->adminToken)
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_a_closed_year_takes_no_new_entries(): void
    {
        $ledger = $this->makeLedger();
        $id = $ledger['fiscalYear'];
        $this->api('POST', "/api/fiscal-years/{$id}/close", [], $this->adminToken)->assertOk();

        // refused as soon as the draft is created
        $this->api('POST', '/api/entries', [
            'fiscalYearId' => $id,
            'entryDate' => '2026-10-28',
            'lines' => [
                ['accountId' => $ledger['cash'], 'debit' => 100],
                ['accountId' => $ledger['bank'], 'credit' => 100],
            ],
        ], $this->adminToken)->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');

        // and the database still refuses to post into it, whatever the application does
        $draft = $this->balancedDraft($ledger);
        $this->api('POST', "/api/entries/{$draft}/post", [], $this->adminToken)
            ->assertStatus(422);
        $this->assertSame('draft', DB::table('journal_entries')->where('id', $draft)->value('status'));
    }

    // -----------------------------------------------------------------
    // Reopening
    // -----------------------------------------------------------------

    public function test_reopening_needs_a_written_reason_and_is_logged(): void
    {
        $ledger = $this->makeLedger();
        $id = $ledger['fiscalYear'];
        $url = "/api/fiscal-years/{$id}/reopen";

        // not closed yet
        $this->api('POST', $url, ['reason' => 'Correcting a missing invoice'], $this->adminToken)
            ->assertStatus(409)->assertJsonPath('message', 'This fiscal year is not closed.');

        $this->api('POST', "/api/fiscal-years/{$id}/close", [], $this->adminToken)->assertOk();

        $this->api('POST', $url, [], $this->adminToken)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->api('POST', $url, ['reason' => 'short'], $this->adminToken)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('closed', DB::table('fiscal_years')->where('id', $id)->value('status'));

        $this->api('POST', $url, ['reason' => 'Correcting a missing invoice'], $this->adminToken)
            ->assertOk()->assertJsonPath('status', 'open');

        $details = json_decode((string)DB::table('audit_logs')
            ->where('action', 'reopen')
            ->where('entity_id', $id)
            ->value('details'), true);
        $this->assertSame('Correcting a missing invoice', $details['reason']);

        // after reopening, entries can be posted again
        $draft = $this->balancedDraft($ledger);
        $this->api('POST', "/api/entries/{$draft}/post", [], $this->adminToken)
            ->assertOk()->assertJsonPath('status', 'posted');
    }

    public function test_changes_are_written_to_the_audit_log(): void
    {
        $id = $this->createYear($this->year1405())->assertStatus(201)->json('id');
        $this->api('PATCH', "/api/fiscal-years/{$id}", ['title' => 'Renamed'], $this->adminToken)->assertOk();
        $this->api('POST', "/api/fiscal-years/{$id}/close", [], $this->adminToken)->assertOk();
        $this->api('POST', "/api/fiscal-years/{$id}/reopen", ['reason' => 'A reason that is long enough'], $this->adminToken)->assertOk();
        $this->api('DELETE', "/api/fiscal-years/{$id}", [], $this->adminToken)->assertOk();

        $actions = DB::table('audit_logs')->where('entity', 'fiscal_year')->orderBy('id')->pluck('action')->all();

        $this->assertSame(['create', 'update', 'close', 'reopen', 'delete'], $actions);
    }
}
