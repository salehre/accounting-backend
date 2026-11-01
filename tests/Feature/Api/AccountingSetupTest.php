<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingSetupTest extends TestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
    }

    /**
     * An equity group "3" > "31" with four movement accounts for the technical roles.
     *
     * @return array{group:int, pl:int, re:int, closing:int, opening:int}
     */
    private function technicalAccounts(): array
    {
        $group = $this->makeAccount('3', 'Equity', 1, 'credit');
        DB::table('account_group_categories')->insert(['account_id' => $group, 'category' => 'equity']);
        $kol = $this->makeAccount('31', 'Capital', 2, 'credit', $group);

        return [
            'group' => $group,
            'pl' => $this->makeAccount('3101', 'Profit and loss', 3, 'credit', $kol, true),
            're' => $this->makeAccount('3102', 'Retained earnings', 3, 'credit', $kol, true),
            'closing' => $this->makeAccount('3103', 'Closing', 3, 'credit', $kol, true),
            'opening' => $this->makeAccount('3104', 'Opening', 3, 'credit', $kol, true),
        ];
    }

    /** @param array<string, int> $accounts */
    private function settingsPayload(array $accounts, array $override = []): array
    {
        return $override + [
                'profitLossAccountId' => $accounts['pl'],
                'retainedEarningsAccountId' => $accounts['re'],
                'closingAccountId' => $accounts['closing'],
                'openingAccountId' => $accounts['opening'],
            ];
    }

    // -----------------------------------------------------------------
    // Categories
    // -----------------------------------------------------------------

    public function test_classifying_account_groups(): void
    {
        $group = $this->makeAccount('1', 'Assets', 1, 'debit');
        $kol = $this->makeAccount('11', 'Current assets', 2, 'debit', $group);
        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $accountant = $this->tokenFor($this->makeUser('accountant'));

        $this->api('GET', '/api/account-categories')->assertStatus(401);

        // everyone who may read accounts sees the groups (and only the groups)
        $list = $this->api('GET', '/api/account-categories', [], $viewer)->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame($group, $list[0]['accountId']);
        $this->assertNull($list[0]['category']);
        $this->assertFalse($list[0]['hasEntries']);

        $this->api('PUT', "/api/account-categories/{$group}", ['category' => 'asset'], $viewer)->assertStatus(403);

        $this->api('PUT', "/api/account-categories/{$group}", ['category' => 'asset'], $accountant)
            ->assertOk()
            ->assertJsonPath('accountId', $group)
            ->assertJsonPath('category', 'asset');

        $this->api('PUT', "/api/account-categories/{$group}", ['category' => 'equity'], $this->adminToken)
            ->assertOk()->assertJsonPath('category', 'equity');

        // the same value again is not a change
        $this->api('PUT', "/api/account-categories/{$group}", ['category' => 'equity'], $this->adminToken)->assertOk();

        $logged = DB::table('audit_logs')->where('action', 'classify')->where('entity_id', $group)->count();
        $this->assertSame(2, $logged);

        // invalid input
        $this->api('PUT', "/api/account-categories/{$group}", ['category' => 'alien'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $this->api('PUT', "/api/account-categories/{$group}", [], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('category');
        $this->api('PUT', "/api/account-categories/{$kol}", ['category' => 'asset'], $this->adminToken)
            ->assertStatus(422);
        $this->api('PUT', '/api/account-categories/999999', ['category' => 'asset'], $this->adminToken)
            ->assertStatus(404);
    }

    public function test_a_group_shows_whether_its_accounts_have_entries(): void
    {
        $ledger = $this->makeLedger();
        $this->balancedDraft($ledger);

        $list = $this->api('GET', '/api/account-categories', [], $this->adminToken)->assertOk()->json();

        $this->assertTrue($list[0]['hasEntries']);
    }

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function test_settings_start_empty_and_can_be_saved(): void
    {
        $accounts = $this->technicalAccounts();
        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $accountant = $this->tokenFor($this->makeUser('accountant'));

        $empty = $this->api('GET', '/api/accounting-settings', [], $viewer)->assertOk()->json();
        $this->assertSame(
            ['profitLossAccountId', 'retainedEarningsAccountId', 'closingAccountId', 'openingAccountId'],
            array_keys($empty),
        );
        $this->assertSame([null, null, null, null], array_values($empty));

        $this->api('PUT', '/api/accounting-settings', $this->settingsPayload($accounts), $viewer)->assertStatus(403);
        $this->api('PUT', '/api/accounting-settings', $this->settingsPayload($accounts))->assertStatus(401);

        $saved = $this->api('PUT', '/api/accounting-settings', $this->settingsPayload($accounts), $accountant)
            ->assertOk()
            ->assertJsonPath('profitLossAccountId.code', '3101')
            ->assertJsonPath('retainedEarningsAccountId.id', $accounts['re'])
            ->assertJsonPath('closingAccountId.name', 'Closing')
            ->assertJsonPath('openingAccountId.isActive', true)
            ->json();

        $this->assertSame($saved, $this->api('GET', '/api/accounting-settings', [], $viewer)->assertOk()->json());

        $this->assertSame(1, DB::table('audit_logs')->where('entity', 'accounting_settings')->count());
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $accounts = $this->technicalAccounts();

        $assetGroup = $this->makeAccount('1', 'Assets', 1, 'debit');
        DB::table('account_group_categories')->insert(['account_id' => $assetGroup, 'category' => 'asset']);
        $assetKol = $this->makeAccount('11', 'Current assets', 2, 'debit', $assetGroup);
        $cash = $this->makeAccount('1101', 'Cash', 3, 'debit', $assetKol, true);
        $needsDetail = $this->makeAccount('1102', 'Customers', 3, 'debit', $assetKol, true, true);
        $disabled = $this->makeAccount('1103', 'Old', 3, 'debit', $assetKol, true);
        DB::table('accounts')->where('id', $disabled)->update(['is_active' => false]);

        $unclassifiedGroup = $this->makeAccount('7', 'Other', 1, 'debit');
        $unclassifiedKol = $this->makeAccount('71', 'Other', 2, 'debit', $unclassifiedGroup);
        $unclassified = $this->makeAccount('7101', 'Misc', 3, 'debit', $unclassifiedKol, true);

        $invalid = [
            'the same account twice' => [['openingAccountId' => $accounts['closing']], 'openingAccountId'],
            'a group account' => [['closingAccountId' => $accounts['group']], 'closingAccountId'],
            'an account that requires a detail account' => [['profitLossAccountId' => $needsDetail], 'profitLossAccountId'],
            'a disabled account' => [['openingAccountId' => $disabled], 'openingAccountId'],
            'retained earnings in an asset group' => [['retainedEarningsAccountId' => $cash], 'retainedEarningsAccountId'],
            'retained earnings in an unclassified group' => [['retainedEarningsAccountId' => $unclassified], 'retainedEarningsAccountId'],
            'an unknown account' => [['closingAccountId' => 999999], 'closingAccountId'],
        ];

        foreach ($invalid as $case => [$override, $errorKey]) {
            $response = $this->api('PUT', '/api/accounting-settings', $this->settingsPayload($accounts, $override), $this->adminToken);

            $response->assertStatus(422);
            $this->assertArrayHasKey(
                $errorKey,
                $response->json('errors') ?? [],
                "The case \"{$case}\" was not rejected for \"{$errorKey}\"",
            );
        }

        // a missing field
        $payload = $this->settingsPayload($accounts);
        unset($payload['closingAccountId']);
        $this->api('PUT', '/api/accounting-settings', $payload, $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('closingAccountId');

        // nothing was saved
        $saved = $this->api('GET', '/api/accounting-settings', [], $this->adminToken)->json();
        $this->assertSame([null, null, null, null], array_values($saved));
    }

    public function test_a_technical_account_cannot_be_deleted(): void
    {
        $accounts = $this->technicalAccounts();
        $this->api('PUT', '/api/accounting-settings', $this->settingsPayload($accounts), $this->adminToken)->assertOk();

        $this->api('DELETE', "/api/accounts/{$accounts['pl']}", [], $this->adminToken)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This account is in use. Disable it instead of deleting it.');

        $this->assertSame(1, DB::table('accounts')->where('id', $accounts['pl'])->count());
    }
}
