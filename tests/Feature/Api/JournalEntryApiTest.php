<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalEntryApiTest extends TestCase
{
    /** @var array{fiscalYear:int, group:int, cash:int, bank:int, receivable:int, detail:int} */
    private array $ledger;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = $this->makeLedger();
        $this->admin = $this->makeUser('admin');
        $this->adminToken = $this->tokenFor($this->admin);
    }

    /** @return array<string, mixed> */
    private function payload(int $amount = 500000): array
    {
        return [
            'fiscalYearId' => $this->ledger['fiscalYear'],
            'entryDate' => '2026-10-28',
            'description' => 'Test entry',
            'lines' => [
                ['accountId' => $this->ledger['cash'], 'debit' => $amount],
                ['accountId' => $this->ledger['bank'], 'credit' => $amount],
            ],
        ];
    }

    private function createDraftViaApi(?array $payload = null, ?string $token = null): int
    {
        $response = $this->api('POST', '/api/entries', $payload ?? $this->payload(), $token ?? $this->adminToken);
        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    private function postViaApi(int $id): void
    {
        $this->api('POST', "/api/entries/{$id}/post", [], $this->adminToken)->assertOk();
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_creating_a_draft_returns_the_whole_entry(): void
    {
        $response = $this->api('POST', '/api/entries', $this->payload(), $this->adminToken);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('number', null)
            ->assertJsonPath('isBalanced', true)
            ->assertJsonPath('createdBy', $this->admin->id)
            ->assertJsonCount(2, 'lines');

        $this->assertSame(500000, $response->json('totalDebit'));
        $this->assertSame(500000, $response->json('lines.0.debit'));
        $this->assertSame('2026-10-28', $response->json('entryDate'));
        $this->assertSame('1101', $response->json('lines.0.accountCode'));
    }

    /** @return array<string, array{0: Closure, 1: string}> */
    public static function invalidPayloads(): array
    {
        return [
            'a single line' => [function (array $p) {
                $p['lines'] = [$p['lines'][0]];

                return $p;
            }, 'lines'],
            'debit and credit on the same line' => [function (array $p) {
                $p['lines'][0]['credit'] = 100;

                return $p;
            }, 'lines.0'],
            'neither debit nor credit' => [function (array $p) {
                $p['lines'][0]['debit'] = 0;

                return $p;
            }, 'lines.0'],
            'negative amount' => [function (array $p) {
                $p['lines'][0]['debit'] = -5;

                return $p;
            }, 'lines.0.debit'],
            'decimal amount' => [function (array $p) {
                $p['lines'][0]['debit'] = 10.5;

                return $p;
            }, 'lines.0.debit'],
            'text amount' => [function (array $p) {
                $p['lines'][0]['debit'] = 'abc';

                return $p;
            }, 'lines.0.debit'],
            'amount above the safe integer limit' => [function (array $p) {
                $p['lines'][0]['debit'] = 9007199254740992;

                return $p;
            }, 'lines.0.debit'],
            'wrong date format' => [function (array $p) {
                $p['entryDate'] = '28-10-2026';

                return $p;
            }, 'entryDate'],
            'impossible date' => [function (array $p) {
                $p['entryDate'] = '2026-02-30';

                return $p;
            }, 'entryDate'],
            'unknown account' => [function (array $p) {
                $p['lines'][0]['accountId'] = 999999;

                return $p;
            }, 'lines.0.accountId'],
            'unknown fiscal year' => [function (array $p) {
                $p['fiscalYearId'] = 999999;

                return $p;
            }, 'fiscalYearId'],
        ];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_entries_are_rejected_and_nothing_is_saved(Closure $mutate, string $errorKey): void
    {
        $response = $this->api('POST', '/api/entries', $mutate($this->payload()), $this->adminToken);

        $response->assertStatus(422)->assertJsonValidationErrors($errorKey);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    // -----------------------------------------------------------------
    // Who may do what
    // -----------------------------------------------------------------

    public function test_every_entry_endpoint_requires_a_token(): void
    {
        $entryId = $this->createDraftViaApi();

        foreach ([
                     ['GET', '/api/entries'],
                     ['POST', '/api/entries'],
                     ['GET', "/api/entries/{$entryId}"],
                     ['DELETE', "/api/entries/{$entryId}"],
                     ['POST', "/api/entries/{$entryId}/post"],
                     ['POST', "/api/entries/{$entryId}/reverse"],
                     ['GET', '/api/accounts'],
                     ['GET', '/api/detail-accounts'],
                     ['GET', '/api/fiscal-years'],
                 ] as [$method, $uri]) {
            $this->api($method, $uri)->assertStatus(401);
        }
    }

    public function test_a_viewer_can_read_but_not_change_anything(): void
    {
        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $entryId = $this->createDraftViaApi();

        $this->api('GET', '/api/entries', [], $viewer)->assertOk();
        $this->api('GET', "/api/entries/{$entryId}", [], $viewer)->assertOk();
        $this->api('GET', '/api/accounts', [], $viewer)->assertOk();

        $this->api('POST', '/api/entries', $this->payload(), $viewer)->assertStatus(403);
        $this->api('POST', "/api/entries/{$entryId}/post", [], $viewer)->assertStatus(403);
        $this->api('POST', "/api/entries/{$entryId}/reverse", [], $viewer)->assertStatus(403);
        $this->api('DELETE', "/api/entries/{$entryId}", [], $viewer)->assertStatus(403);

        $this->assertSame('draft', DB::table('journal_entries')->where('id', $entryId)->value('status'));
    }

    public function test_an_accountant_can_do_the_whole_flow(): void
    {
        $accountant = $this->tokenFor($this->makeUser('accountant'));

        $id = $this->createDraftViaApi(null, $accountant);
        $this->api('POST', "/api/entries/{$id}/post", [], $accountant)
            ->assertOk()->assertJsonPath('status', 'posted');
        $this->api('POST', "/api/entries/{$id}/reverse", [], $accountant)->assertStatus(201);

        $draftId = $this->createDraftViaApi(null, $accountant);
        $this->api('DELETE', "/api/entries/{$draftId}", [], $accountant)->assertOk();
    }

    // -----------------------------------------------------------------
    // Posting, deleting, reversing
    // -----------------------------------------------------------------

    public function test_an_unbalanced_draft_can_be_saved_but_never_posted(): void
    {
        $payload = $this->payload();
        $payload['lines'][1]['credit'] = 400000;
        $id = $this->createDraftViaApi($payload);

        $this->api('GET', "/api/entries/{$id}", [], $this->adminToken)
            ->assertJsonPath('isBalanced', false);

        $this->api('POST', "/api/entries/{$id}/post", [], $this->adminToken)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Entry is not balanced: debit 500000 <> credit 400000.');

        $this->api('DELETE', "/api/entries/{$id}", [], $this->adminToken)->assertOk();
        $this->api('GET', "/api/entries/{$id}", [], $this->adminToken)->assertStatus(404);
    }

    public function test_posting_numbers_the_entry_and_it_cannot_be_posted_or_deleted_again(): void
    {
        $id = $this->createDraftViaApi();

        $this->api('POST', "/api/entries/{$id}/post", [], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('number', 1)
            ->assertJsonPath('postedBy', $this->admin->id);

        $this->api('POST', "/api/entries/{$id}/post", [], $this->adminToken)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Entry is already posted');

        $this->api('DELETE', "/api/entries/{$id}", [], $this->adminToken)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Posted entries cannot be deleted. Create a reversing entry instead.');
    }

    public function test_unknown_entries_return_404(): void
    {
        $this->api('GET', '/api/entries/999999', [], $this->adminToken)->assertStatus(404);
        $this->api('POST', '/api/entries/999999/post', [], $this->adminToken)->assertStatus(404);
        $this->api('DELETE', '/api/entries/999999', [], $this->adminToken)->assertStatus(404);
    }

    public function test_reversing_creates_a_swapped_draft_that_can_be_posted_once(): void
    {
        $id = $this->createDraftViaApi();
        $this->postViaApi($id);

        $reversal = $this->api('POST', "/api/entries/{$id}/reverse", [], $this->adminToken)
            ->assertStatus(201)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('sourceType', 'reversal')
            ->assertJsonPath('reversesEntryId', $id);

        $this->assertSame(500000, $reversal->json('lines.0.credit'));
        $this->assertSame(500000, $reversal->json('lines.1.debit'));

        // A second reversal is refused
        $this->api('POST', "/api/entries/{$id}/reverse", [], $this->adminToken)->assertStatus(409);

        $reversalId = (int) $reversal->json('id');
        $this->api('POST', "/api/entries/{$reversalId}/post", [], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('number', 2);

        // A draft cannot be reversed
        $draftId = $this->createDraftViaApi();
        $this->api('POST', "/api/entries/{$draftId}/reverse", [], $this->adminToken)->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    public function test_listing_filters_orders_and_paginates(): void
    {
        foreach (range(1, 12) as $day) {
            $payload = $this->payload(1000 * $day);
            $payload['entryDate'] = sprintf('2026-10-%02d', $day);
            $this->postViaApi($this->createDraftViaApi($payload));
        }

        $draftPayload = $this->payload();
        $draftPayload['entryDate'] = '2026-10-05';
        $this->createDraftViaApi($draftPayload);

        // Newest first, 5 per page
        $page1 = $this->api('GET', '/api/entries?pageSize=5&page=1', [], $this->adminToken)->assertOk();
        $this->assertCount(5, $page1->json('items'));
        $this->assertSame(13, $page1->json('total'));
        $this->assertSame('2026-10-12', $page1->json('items.0.entryDate'));
        $this->assertSame(12, $page1->json('items.0.number'));

        $page3 = $this->api('GET', '/api/entries?pageSize=5&page=3', [], $this->adminToken)->assertOk();
        $this->assertCount(3, $page3->json('items'));

        // Filters
        $drafts = $this->api('GET', '/api/entries?status=draft', [], $this->adminToken);
        $this->assertSame(1, $drafts->json('total'));

        $posted = $this->api('GET', '/api/entries?status=posted', [], $this->adminToken);
        $this->assertSame(12, $posted->json('total'));

        $otherYear = $this->api('GET', '/api/entries?fiscalYearId=999999', [], $this->adminToken);
        $this->assertSame(0, $otherYear->json('total'));

        // Bad query values
        $this->api('GET', '/api/entries?pageSize=101', [], $this->adminToken)->assertStatus(422);
        $this->api('GET', '/api/entries?page=0', [], $this->adminToken)->assertStatus(422);
        $this->api('GET', '/api/entries?status=weird', [], $this->adminToken)->assertStatus(422);
    }

    public function test_amounts_are_exact_integers_and_dates_stay_plain_strings(): void
    {
        $amount = 9_007_199_254_740_991;
        $id = $this->createDraftViaApi($this->payload($amount));
        $this->postViaApi($id);

        $entry = $this->api('GET', "/api/entries/{$id}", [], $this->adminToken)->assertOk();

        $this->assertSame($amount, $entry->json('totalDebit'));
        $this->assertSame($amount, $entry->json('lines.0.debit'));
        $this->assertSame($amount, $entry->json('lines.1.credit'));
        $this->assertSame('2026-10-28', $entry->json('entryDate'));
    }
}
