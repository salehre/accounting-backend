<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DetailAccountManagementTest extends TestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
    }

    /** @param array<string, mixed> $payload */
    private function createDetail(array $payload, ?string $token = null): TestResponse
    {
        return $this->api('POST', '/api/detail-accounts', $payload, $token ?? $this->adminToken);
    }

    public function test_viewers_can_read_but_not_change_and_accountants_can(): void
    {
        $viewer = $this->tokenFor($this->makeUser('viewer'));
        $accountant = $this->tokenFor($this->makeUser('accountant'));

        foreach ([['POST', '/api/detail-accounts'], ['PATCH', '/api/detail-accounts/1'], ['DELETE', '/api/detail-accounts/1']] as [$method, $uri]) {
            $this->api($method, $uri)->assertStatus(401);
            $this->api($method, $uri, [], $viewer)->assertStatus(403);
        }

        $this->api('GET', '/api/detail-accounts', [], $viewer)->assertOk();

        $this->createDetail(['code' => 'C001', 'name' => 'Ali'], $accountant)->assertStatus(201);
    }

    public function test_creating_detail_accounts(): void
    {
        $response = $this->createDetail(['code' => 'C001', 'name' => 'Ali Rezaei', 'kind' => 'customer']);

        $response->assertStatus(201)
            ->assertJsonPath('code', 'C001')
            ->assertJsonPath('name', 'Ali Rezaei')
            ->assertJsonPath('kind', 'customer')
            ->assertJsonPath('isActive', true)
            ->assertJsonPath('hasEntries', false);

        // the kind defaults to "other"
        $this->createDetail(['code' => 'X1', 'name' => 'Something'])->assertStatus(201)->assertJsonPath('kind', 'other');

        $invalid = [
            'a code with a space' => [['code' => 'bad code', 'name' => 'X'], 'code'],
            'a duplicate code' => [['code' => 'C001', 'name' => 'X'], 'code'],
            'an unknown kind' => [['code' => 'C002', 'name' => 'X', 'kind' => 'alien'], 'kind'],
            'a missing name' => [['code' => 'C003'], 'name'],
        ];

        foreach ($invalid as $case => [$payload, $errorKey]) {
            $this->createDetail($payload)->assertStatus(422)
                ->assertJsonValidationErrors($errorKey, 'The case "'.$case.'" was not rejected for "'.$errorKey.'"');
        }

        $this->assertSame(2, DB::table('detail_accounts')->count());
    }

    public function test_searching_filtering_and_limiting(): void
    {
        $this->createDetail(['code' => 'C001', 'name' => 'Ali', 'kind' => 'customer'])->assertStatus(201);
        $this->createDetail(['code' => 'C002', 'name' => 'Maryam', 'kind' => 'customer'])->assertStatus(201);
        $parts = $this->createDetail(['code' => 'S001', 'name' => 'Parts Ltd', 'kind' => 'supplier'])->assertStatus(201)->json('id');

        $codes = fn (string $query) => array_column(
            $this->api('GET', '/api/detail-accounts'.$query, [], $this->adminToken)->assertOk()->json(),
            'code',
        );

        $this->assertSame(['C001', 'C002', 'S001'], $codes('')); // ordered by code
        $this->assertSame(['C002'], $codes('?search=MARYAM'));
        $this->assertSame(['C001', 'C002'], $codes('?search=c00&kind=customer'));
        $this->assertSame(['S001'], $codes('?kind=supplier'));
        $this->assertSame(['C001'], $codes('?limit=1'));
        $this->assertSame([], $codes('?search=%25')); // % is a normal character, not a wildcard

        $this->api('GET', '/api/detail-accounts?limit=201', [], $this->adminToken)->assertStatus(422);
        $this->api('GET', '/api/detail-accounts?kind=alien', [], $this->adminToken)->assertStatus(422);

        // disabled ones are hidden unless asked for
        $this->api('PATCH', "/api/detail-accounts/{$parts}", ['isActive' => false], $this->adminToken)->assertOk();
        $this->assertSame(['C001', 'C002'], $codes(''));
        $this->assertSame(['C001', 'C002', 'S001'], $codes('?includeInactive=1'));
    }

    public function test_updating_an_unused_detail_account(): void
    {
        $id = $this->createDetail(['code' => 'C001', 'name' => 'Ali', 'kind' => 'customer'])->assertStatus(201)->json('id');
        $this->createDetail(['code' => 'C002', 'name' => 'Maryam'])->assertStatus(201);
        $url = "/api/detail-accounts/{$id}";

        $this->api('PATCH', $url, ['name' => 'Ali Rezaei', 'kind' => 'supplier', 'code' => 'C009'], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('name', 'Ali Rezaei')
            ->assertJsonPath('kind', 'supplier')
            ->assertJsonPath('code', 'C009');

        $this->api('PATCH', $url, ['code' => 'C002'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->api('PATCH', $url, ['kind' => 'alien'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->api('PATCH', $url, [], $this->adminToken)->assertStatus(422);
        $this->api('PATCH', '/api/detail-accounts/999999', ['name' => 'Nobody'], $this->adminToken)->assertStatus(404);
    }

    public function test_a_detail_account_with_entries_keeps_its_code_and_cannot_be_deleted(): void
    {
        $ledger = $this->makeLedger();
        $this->draftEntry($ledger, [
            ['accountId' => $ledger['receivable'], 'detailId' => $ledger['detail'], 'debit' => 100],
            ['accountId' => $ledger['cash'], 'credit' => 100],
        ]);
        $url = "/api/detail-accounts/{$ledger['detail']}";

        $this->api('PATCH', $url, ['code' => 'NEWCODE'], $this->adminToken)
            ->assertStatus(422)
            ->assertJsonPath('message', 'This detail account is already in use: its code can no longer be changed.');

        // name, kind and status can still change
        $this->api('PATCH', $url, ['name' => 'Renamed', 'kind' => 'supplier'], $this->adminToken)
            ->assertOk()->assertJsonPath('hasEntries', true);

        $this->api('DELETE', $url, [], $this->adminToken)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This detail account is in use. Disable it instead of deleting it.');
    }

    public function test_deleting_an_unused_detail_account(): void
    {
        $id = $this->createDetail(['code' => 'C001', 'name' => 'Ali'])->assertStatus(201)->json('id');

        $this->api('DELETE', "/api/detail-accounts/{$id}", [], $this->adminToken)->assertOk()->assertJsonPath('ok', true);
        $this->api('DELETE', "/api/detail-accounts/{$id}", [], $this->adminToken)->assertStatus(404);

        $this->assertSame(0, DB::table('detail_accounts')->count());
    }

    public function test_changes_are_written_to_the_audit_log(): void
    {
        $id = $this->createDetail(['code' => 'C001', 'name' => 'Ali'])->assertStatus(201)->json('id');
        $this->api('PATCH', "/api/detail-accounts/{$id}", ['name' => 'Ali Rezaei'], $this->adminToken)->assertOk();
        $this->api('DELETE', "/api/detail-accounts/{$id}", [], $this->adminToken)->assertOk();

        $actions = DB::table('audit_logs')->where('entity', 'detail_account')->orderBy('id')->pluck('action')->all();

        $this->assertSame(['create', 'update', 'delete'], $actions);
    }
}
