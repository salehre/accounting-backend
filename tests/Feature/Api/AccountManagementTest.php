<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
    }

    /** @param array<string, mixed> $payload */
    private function createAccount(array $payload, ?string $token = null): TestResponse
    {
        return $this->api('POST', '/api/accounts', $payload, $token ?? $this->adminToken);
    }

    /**
     * Builds group "1" > kol "11" > moein "1101" through the API.
     *
     * @return array{group:int, kol:int, moein:int}
     */
    private function buildTree(): array
    {
        $group = $this->createAccount(['level' => 1, 'code' => '1', 'name' => 'Assets', 'nature' => 'debit'])
            ->assertStatus(201)->json('id');
        $kol = $this->createAccount(['level' => 2, 'parentId' => $group, 'code' => '11', 'name' => 'Current assets', 'nature' => 'debit'])
            ->assertStatus(201)->json('id');
        $moein = $this->createAccount(['level' => 3, 'parentId' => $kol, 'code' => '1101', 'name' => 'Cash', 'nature' => 'debit'])
            ->assertStatus(201)->json('id');

        return ['group' => $group, 'kol' => $kol, 'moein' => $moein];
    }

    // -----------------------------------------------------------------
    // Who may do what
    // -----------------------------------------------------------------

    public function test_viewers_can_read_the_chart_but_not_change_it_and_accountants_can(): void
    {
        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $accountant = $this->tokenFor($this->makeUser('accountant'));

        foreach ([['POST', '/api/accounts'], ['PATCH', '/api/accounts/1'], ['DELETE', '/api/accounts/1']] as [$method, $uri]) {
            $this->api($method, $uri)->assertStatus(401);
            $this->api($method, $uri, [], $viewer)->assertStatus(403);
        }

        $this->api('GET', '/api/accounts', [], $viewer)->assertOk();

        $this->createAccount(['level' => 1, 'code' => '1', 'name' => 'Assets', 'nature' => 'debit'], $accountant)
            ->assertStatus(201);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_building_a_chart_through_the_api(): void
    {
        $ids = $this->buildTree();

        $list = $this->api('GET', '/api/accounts', [], $this->adminToken)->assertOk()->json();
        $byCode = collect($list)->keyBy('code');

        $this->assertSame(['1', '11', '1101'], array_column($list, 'code')); // tree order

        $this->assertSame(1, $byCode['1']['level']);
        $this->assertNull($byCode['1']['parentId']);
        $this->assertFalse($byCode['1']['isPostable']);
        $this->assertTrue($byCode['1']['hasChildren']);

        $this->assertSame($ids['kol'], $byCode['1101']['parentId']);
        $this->assertTrue($byCode['1101']['isPostable']);
        $this->assertFalse($byCode['1101']['requiresDetail']);
        $this->assertFalse($byCode['1101']['hasChildren']);
        $this->assertFalse($byCode['1101']['hasEntries']);
        $this->assertTrue($byCode['1101']['isActive']);
    }

    public function test_the_structure_rules_are_enforced(): void
    {
        $ids = $this->buildTree();

        $invalid = [
            'a group with a parent' => [['level' => 1, 'parentId' => $ids['kol'], 'code' => '2'], 'parentId'],
            'a kol without a parent' => [['level' => 2, 'code' => '12'], 'parentId'],
            'a moein directly under a group' => [['level' => 3, 'parentId' => $ids['group'], 'code' => '1999'], 'parentId'],
            'a kol under a kol' => [['level' => 2, 'parentId' => $ids['kol'], 'code' => '111'], 'parentId'],
            'an unknown parent' => [['level' => 2, 'parentId' => 999999, 'code' => '12'], 'parentId'],
            'a code that does not start with the parent code' => [['level' => 2, 'parentId' => $ids['group'], 'code' => '21'], 'code'],
            'a code equal to the parent code' => [['level' => 3, 'parentId' => $ids['kol'], 'code' => '11'], 'code'],
            'a duplicate code' => [['level' => 2, 'parentId' => $ids['group'], 'code' => '11'], 'code'],
            'a code with letters' => [['level' => 2, 'parentId' => $ids['group'], 'code' => '1A'], 'code'],
            'requiresDetail on a kol' => [['level' => 2, 'parentId' => $ids['group'], 'code' => '12', 'requiresDetail' => true], 'requiresDetail'],
            'an unknown level' => [['level' => 4, 'parentId' => $ids['moein'], 'code' => '11011'], 'level'],
            'an unknown nature' => [['level' => 2, 'parentId' => $ids['group'], 'code' => '12', 'nature' => 'sideways'], 'nature'],
        ];

        foreach ($invalid as $case => [$override, $errorKey]) {
            $this->createAccount($override + ['name' => 'Test', 'nature' => 'debit'])
                ->assertStatus(422)
                ->assertJsonValidationErrors($errorKey, 'The case "'.$case.'" was not rejected for "'.$errorKey.'"');
        }

        $this->assertSame(3, DB::table('accounts')->count()); // nothing was saved
    }

    public function test_accounts_created_through_the_api_work_in_journal_entries(): void
    {
        $ids = $this->buildTree();
        $bank = $this->createAccount(['level' => 3, 'parentId' => $ids['kol'], 'code' => '1102', 'name' => 'Bank', 'nature' => 'debit'])
            ->assertStatus(201)->json('id');
        $fiscalYear = DB::table('fiscal_years')->insertGetId([
            'title' => '1405',
            'start_date' => '2026-03-21',
            'end_date' => '2027-03-20',
        ]);

        $entry = $this->api('POST', '/api/entries', [
            'fiscalYearId' => $fiscalYear,
            'entryDate' => '2026-10-28',
            'lines' => [
                ['accountId' => $ids['moein'], 'debit' => 1000],
                ['accountId' => $bank, 'credit' => 1000],
            ],
        ], $this->adminToken)->assertStatus(201)->json('id');

        $this->api('POST', "/api/entries/{$entry}/post", [], $this->adminToken)->assertOk();

        $list = collect($this->api('GET', '/api/accounts', [], $this->adminToken)->json())->keyBy('code');
        $this->assertTrue($list['1101']['hasEntries']);
        $this->assertTrue($list['1102']['hasEntries']);
        $this->assertFalse($list['1']['hasEntries']);
    }

    // -----------------------------------------------------------------
    // Updating
    // -----------------------------------------------------------------

    public function test_an_unused_account_can_change_everything_except_its_position(): void
    {
        $ids = $this->buildTree();
        $url = "/api/accounts/{$ids['moein']}";

        $this->api('PATCH', $url, [
            'name' => 'Cash box',
            'code' => '1105',
            'nature' => 'both',
            'requiresDetail' => true,
        ], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('name', 'Cash box')
            ->assertJsonPath('code', '1105')
            ->assertJsonPath('nature', 'both')
            ->assertJsonPath('requiresDetail', true);

        // the new code must still follow the parent
        $this->api('PATCH', $url, ['code' => '2105'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('code');

        // the position in the tree is fixed
        $this->api('PATCH', $url, ['level' => 2], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('level');
        $this->api('PATCH', $url, ['parentId' => $ids['group']], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('parentId');

        // requiresDetail only makes sense on a moein
        $lonelyGroup = $this->createAccount(['level' => 1, 'code' => '2', 'name' => 'Liabilities', 'nature' => 'credit'])
            ->assertStatus(201)->json('id');
        $this->api('PATCH', "/api/accounts/{$lonelyGroup}", ['requiresDetail' => true], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('requiresDetail');

        // other invalid input
        $this->api('PATCH', $url, [], $this->adminToken)->assertStatus(422);
        $this->api('PATCH', $url, ['nature' => 'sideways'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('nature');
        $this->api('PATCH', $url, ['code' => '1A'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->api('PATCH', '/api/accounts/999999', ['name' => 'Nobody'], $this->adminToken)->assertStatus(404);
    }

    public function test_an_account_with_entries_or_children_keeps_its_structure(): void
    {
        $ledger = $this->makeLedger(); // accounts 1 > 11 > 1101, 1102, 1103
        $this->balancedDraft($ledger); // draft lines on cash (1101) and bank (1102)

        $cash = "/api/accounts/{$ledger['cash']}";

        foreach ([['code' => '1109'], ['nature' => 'credit'], ['requiresDetail' => true]] as $change) {
            $this->api('PATCH', $cash, $change, $this->adminToken)
                ->assertStatus(422)
                ->assertJsonPath('message', 'This account is already in use: only its name and active status can be changed.');
        }

        // an account with children is in use as well
        $this->api('PATCH', "/api/accounts/{$ledger['group']}", ['code' => '5'], $this->adminToken)->assertStatus(422);

        // the name and the active status can always change
        $this->api('PATCH', $cash, ['name' => 'Petty cash'], $this->adminToken)
            ->assertOk()->assertJsonPath('name', 'Petty cash');
        $this->api('PATCH', $cash, ['isActive' => false], $this->adminToken)
            ->assertOk()->assertJsonPath('isActive', false);

        // sending the same value again is not a change
        $this->api('PATCH', $cash, ['code' => '1101'], $this->adminToken)->assertOk();

        $this->assertSame('1101', DB::table('accounts')->where('id', $ledger['cash'])->value('code'));
    }

    public function test_disabling_and_enabling_accounts(): void
    {
        $ids = $this->buildTree();

        // a parent cannot be disabled while a child is active
        $this->api('PATCH', "/api/accounts/{$ids['kol']}", ['isActive' => false], $this->adminToken)
            ->assertStatus(422)->assertJsonPath('message', 'Disable the child accounts first.');

        $this->api('PATCH', "/api/accounts/{$ids['moein']}", ['isActive' => false], $this->adminToken)->assertOk();
        $this->api('PATCH', "/api/accounts/{$ids['kol']}", ['isActive' => false], $this->adminToken)->assertOk();

        // a child cannot be enabled under a disabled parent, and no new child can be created there
        $this->api('PATCH', "/api/accounts/{$ids['moein']}", ['isActive' => true], $this->adminToken)
            ->assertStatus(422)->assertJsonPath('message', 'Enable the parent account first.');
        $this->createAccount(['level' => 3, 'parentId' => $ids['kol'], 'code' => '1103', 'name' => 'New', 'nature' => 'debit'])
            ->assertStatus(422)->assertJsonValidationErrors('parentId');

        // the entry form only asks for active accounts
        $active = $this->api('GET', '/api/accounts?activeOnly=1', [], $this->adminToken)->assertOk()->json();
        $this->assertSame(['1'], array_column($active, 'code'));
        $this->assertCount(3, $this->api('GET', '/api/accounts', [], $this->adminToken)->json());

        $this->api('PATCH', "/api/accounts/{$ids['kol']}", ['isActive' => true], $this->adminToken)->assertOk();
        $this->api('PATCH', "/api/accounts/{$ids['moein']}", ['isActive' => true], $this->adminToken)->assertOk();
    }

    public function test_a_disabled_account_cannot_receive_new_entries(): void
    {
        $ids = $this->buildTree();
        $bank = $this->createAccount(['level' => 3, 'parentId' => $ids['kol'], 'code' => '1102', 'name' => 'Bank', 'nature' => 'debit'])
            ->assertStatus(201)->json('id');
        $fiscalYear = DB::table('fiscal_years')->insertGetId([
            'title' => '1405',
            'start_date' => '2026-03-21',
            'end_date' => '2027-03-20',
        ]);

        $this->api('PATCH', "/api/accounts/{$ids['moein']}", ['isActive' => false], $this->adminToken)->assertOk();

        $entry = $this->api('POST', '/api/entries', [
            'fiscalYearId' => $fiscalYear,
            'entryDate' => '2026-10-28',
            'lines' => [
                ['accountId' => $ids['moein'], 'debit' => 1000],
                ['accountId' => $bank, 'credit' => 1000],
            ],
        ], $this->adminToken)->assertStatus(201)->json('id');

        $this->api('POST', "/api/entries/{$entry}/post", [], $this->adminToken)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Entry has 1 invalid line(s): non-postable/inactive account or missing detail account.');
    }

    // -----------------------------------------------------------------
    // Deleting
    // -----------------------------------------------------------------

    public function test_deleting_accounts(): void
    {
        $ids = $this->buildTree();
        $inUse = 'This account is in use. Disable it instead of deleting it.';

        // accounts with children cannot be deleted
        $this->api('DELETE', "/api/accounts/{$ids['group']}", [], $this->adminToken)
            ->assertStatus(409)->assertJsonPath('message', $inUse);
        $this->api('DELETE', "/api/accounts/{$ids['kol']}", [], $this->adminToken)->assertStatus(409);

        // from the bottom up it works
        $this->api('DELETE', "/api/accounts/{$ids['moein']}", [], $this->adminToken)->assertOk()->assertJsonPath('ok', true);
        $this->api('DELETE', "/api/accounts/{$ids['kol']}", [], $this->adminToken)->assertOk();
        $this->api('DELETE', "/api/accounts/{$ids['group']}", [], $this->adminToken)->assertOk();

        $this->assertSame(0, DB::table('accounts')->count());
        $this->api('DELETE', '/api/accounts/999999', [], $this->adminToken)->assertStatus(404);
    }

    public function test_an_account_with_entries_cannot_be_deleted(): void
    {
        $ledger = $this->makeLedger();
        $this->balancedDraft($ledger);

        $this->api('DELETE', "/api/accounts/{$ledger['cash']}", [], $this->adminToken)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This account is in use. Disable it instead of deleting it.');

        $this->assertSame(1, DB::table('accounts')->where('id', $ledger['cash'])->count());
    }

    // -----------------------------------------------------------------
    // Audit
    // -----------------------------------------------------------------

    public function test_changes_are_written_to_the_audit_log(): void
    {
        $ids = $this->buildTree();

        $this->api('PATCH', "/api/accounts/{$ids['moein']}", ['name' => 'Cash box'], $this->adminToken)->assertOk();
        $this->api('DELETE', "/api/accounts/{$ids['moein']}", [], $this->adminToken)->assertOk();

        $actions = DB::table('audit_logs')
            ->where('entity', 'account')
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['create', 'create', 'create', 'update', 'delete'], $actions);

        $details = json_decode((string) DB::table('audit_logs')
            ->where('entity', 'account')
            ->where('action', 'update')
            ->value('details'), true);
        $this->assertSame('Cash', $details['changes']['name']['from']);
        $this->assertSame('Cash box', $details['changes']['name']['to']);
    }
}
