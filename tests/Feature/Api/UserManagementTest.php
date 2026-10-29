<?php

namespace Tests\Feature\Api;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // $this->actor is an administrator created by TestCase
        $this->adminToken = $this->tokenFor($this->actor);
    }

    private function roleId(string $name): int
    {
        return (int) Role::query()->where('name', $name)->value('id');
    }

    /** @return array<string, mixed> */
    private function payload(array $override = []): array
    {
        return array_merge([
            'username' => 'newuser',
            'fullName' => 'New User',
            'password' => 'a-long-enough-password',
            'roleId' => $this->roleId('viewer'),
        ], $override);
    }

    private function login(string $username, string $password): TestResponse
    {
        return $this->api('POST', '/api/auth/token', [
            'username' => $username,
            'password' => $password,
            'device_name' => 'phpunit',
        ]);
    }

    // -----------------------------------------------------------------
    // Who may use these endpoints
    // -----------------------------------------------------------------

    public function test_user_endpoints_are_for_administrators_only(): void
    {
        $accountant = $this->tokenFor($this->makeUser('accountant'));
        $viewer = $this->tokenFor($this->makeUser('viewer'));

        foreach ([
                     ['GET', '/api/users'],
                     ['POST', '/api/users'],
                     ['GET', '/api/users/1'],
                     ['PATCH', '/api/users/1'],
                     ['POST', '/api/users/1/reset-password'],
                     ['GET', '/api/roles'],
                 ] as [$method, $uri]) {
            $this->api($method, $uri)->assertStatus(401);
            $this->api($method, $uri, [], $accountant)->assertStatus(403);
            $this->api($method, $uri, [], $viewer)->assertStatus(403);
        }
    }

    public function test_the_roles_endpoint_lists_roles_with_their_permissions(): void
    {
        $roles = collect($this->api('GET', '/api/roles', [], $this->adminToken)->assertOk()->json())
            ->keyBy('name');

        $this->assertSame(['*'], $roles['admin']['permissions']);
        $this->assertContains('entries.post', $roles['accountant']['permissions']);
        $this->assertNotContains('entries.post', $roles['viewer']['permissions']);
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    public function test_listing_users_with_search_filter_and_pagination(): void
    {
        $this->makeUser('viewer', 'anna');
        $bob = $this->makeUser('accountant', 'bob');
        $this->makeUser('viewer', 'carol');

        $all = $this->api('GET', '/api/users', [], $this->adminToken)->assertOk();
        $this->assertSame(4, $all->json('total')); // the three above + the administrator

        $this->assertEqualsCanonicalizing(
            ['id', 'username', 'fullName', 'roleId', 'role', 'isActive', 'lastLoginAt', 'createdAt'],
            array_keys($all->json('items.0')),
        );

        $page2 = $this->api('GET', '/api/users?pageSize=2&page=2', [], $this->adminToken)->assertOk();
        $this->assertCount(2, $page2->json('items'));
        $this->assertSame(4, $page2->json('total'));

        $search = $this->api('GET', '/api/users?search=ANN', [], $this->adminToken)->assertOk();
        $this->assertSame(1, $search->json('total'));
        $this->assertSame('anna', $search->json('items.0.username'));

        // LIKE wildcards in the search text are treated as plain characters
        $this->assertSame(0, $this->api('GET', '/api/users?search=%25', [], $this->adminToken)->json('total'));

        DB::table('users')->where('id', $bob->id)->update(['is_active' => false]);
        $inactive = $this->api('GET', '/api/users?isActive=0', [], $this->adminToken)->assertOk();
        $this->assertSame(1, $inactive->json('total'));
        $this->assertSame('bob', $inactive->json('items.0.username'));
        $this->assertSame(3, $this->api('GET', '/api/users?isActive=1', [], $this->adminToken)->json('total'));

        $this->api('GET', '/api/users?pageSize=101', [], $this->adminToken)->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Creating
    // -----------------------------------------------------------------

    public function test_creating_a_user(): void
    {
        $response = $this->api('POST', '/api/users', $this->payload(['username' => '  NewUser ']), $this->adminToken);

        $response->assertStatus(201)
            ->assertJsonPath('username', 'newuser') // trimmed and lower-cased
            ->assertJsonPath('fullName', 'New User')
            ->assertJsonPath('role', 'viewer')
            ->assertJsonPath('isActive', true);

        // The password is stored hashed, and works
        $hash = (string) DB::table('users')->where('username', 'newuser')->value('password_hash');
        $this->assertNotSame('a-long-enough-password', $hash);
        $this->assertTrue(Hash::check('a-long-enough-password', $hash));
        $this->login('newuser', 'a-long-enough-password')->assertOk();

        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'create')
            ->where('entity', 'user')
            ->where('user_id', $this->actor->id)
            ->count());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidNewUsers(): array
    {
        return [
            'username too short' => [['username' => 'ab'], 'username'],
            'username with a space' => [['username' => 'bad name'], 'username'],
            'username with a forbidden character' => [['username' => 'bad/name'], 'username'],
            'missing full name' => [['fullName' => ''], 'fullName'],
            'short password' => [['password' => 'short'], 'password'],
            'password longer than 72 bytes' => [['password' => str_repeat('x', 73)], 'password'],
            'unknown role' => [['roleId' => 999999], 'roleId'],
        ];
    }

    #[DataProvider('invalidNewUsers')]
    public function test_invalid_new_users_are_rejected(array $override, string $errorKey): void
    {
        $before = DB::table('users')->count();

        $this->api('POST', '/api/users', $this->payload($override), $this->adminToken)
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);

        $this->assertSame($before, DB::table('users')->count());
    }

    public function test_usernames_are_unique_ignoring_case(): void
    {
        $this->makeUser('viewer', 'taken');

        $this->api('POST', '/api/users', $this->payload(['username' => 'TAKEN']), $this->adminToken)
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    // -----------------------------------------------------------------
    // Updating
    // -----------------------------------------------------------------

    public function test_updating_name_and_role_takes_effect_on_existing_tokens(): void
    {
        $user = $this->makeUser('viewer', 'editme');
        $token = $this->tokenFor($user);

        $this->assertNotContains('entries.post', $this->api('GET', '/api/auth/me', [], $token)->json('permissions'));

        $this->api('PATCH', "/api/users/{$user->id}", [
            'fullName' => 'Edited Name',
            'roleId' => $this->roleId('accountant'),
        ], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('fullName', 'Edited Name')
            ->assertJsonPath('role', 'accountant');

        // Same token, new permissions: permissions are read from the role on every request
        $this->assertContains('entries.post', $this->api('GET', '/api/auth/me', [], $token)->json('permissions'));

        $details = json_decode((string) DB::table('audit_logs')
            ->where('action', 'update')
            ->where('entity_id', $user->id)
            ->value('details'), true);
        $this->assertArrayHasKey('roleId', $details['changes']);
        $this->assertArrayHasKey('fullName', $details['changes']);
    }

    public function test_invalid_updates_are_rejected(): void
    {
        $user = $this->makeUser('viewer');

        $this->api('PATCH', "/api/users/{$user->id}", [], $this->adminToken)->assertStatus(422);
        $this->api('PATCH', "/api/users/{$user->id}", ['roleId' => 999999], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('roleId');
        $this->api('PATCH', "/api/users/{$user->id}", ['isActive' => 'maybe'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('isActive');
        $this->api('PATCH', '/api/users/999999', ['fullName' => 'Nobody'], $this->adminToken)->assertStatus(404);
        $this->api('GET', '/api/users/999999', [], $this->adminToken)->assertStatus(404);
    }

    public function test_disabling_a_user_signs_them_out_everywhere_and_enabling_restores_access(): void
    {
        $victim = $this->makeUser('viewer', 'victim');
        $token = $this->tokenFor($victim);
        $this->api('GET', '/api/auth/me', [], $token)->assertOk();

        $this->api('PATCH', "/api/users/{$victim->id}", ['isActive' => false], $this->adminToken)
            ->assertOk()->assertJsonPath('isActive', false);

        $this->api('GET', '/api/auth/me', [], $token)->assertStatus(401);
        $this->login('victim', self::PASSWORD)->assertStatus(401);

        $this->api('PATCH', "/api/users/{$victim->id}", ['isActive' => true], $this->adminToken)->assertOk();
        $this->login('victim', self::PASSWORD)->assertOk();
    }

    public function test_nobody_can_lock_themselves_out(): void
    {
        $id = $this->actor->id;

        $this->api('PATCH', "/api/users/{$id}", ['isActive' => false], $this->adminToken)->assertStatus(422);
        $this->api('PATCH', "/api/users/{$id}", ['roleId' => $this->roleId('viewer')], $this->adminToken)->assertStatus(422);

        // Changing only the name is fine
        $this->api('PATCH', "/api/users/{$id}", ['fullName' => 'Renamed Admin'], $this->adminToken)->assertOk();

        $this->api('GET', '/api/users', [], $this->adminToken)->assertOk();
    }

    public function test_the_last_active_administrator_cannot_be_removed(): void
    {
        // A role that may manage users but is not an administrator
        DB::table('roles')->insert([
            'name' => 'user_manager',
            'permissions' => json_encode(['users.manage']),
        ]);
        $manager = $this->tokenFor($this->makeUser('user_manager'));

        $adminId = $this->actor->id;
        $message = 'At least one active administrator must remain.';

        $this->api('PATCH', "/api/users/{$adminId}", ['isActive' => false], $manager)
            ->assertStatus(422)->assertJsonPath('message', $message);
        $this->api('PATCH', "/api/users/{$adminId}", ['roleId' => $this->roleId('viewer')], $manager)
            ->assertStatus(422)->assertJsonPath('message', $message);

        // With a second administrator in place the first one can be disabled...
        $second = $this->makeUser('admin');
        $this->api('PATCH', "/api/users/{$adminId}", ['isActive' => false], $manager)->assertOk();

        // ...but then the second one is the last
        $this->api('PATCH', "/api/users/{$second->id}", ['isActive' => false], $manager)
            ->assertStatus(422)->assertJsonPath('message', $message);
    }

    // -----------------------------------------------------------------
    // Resetting a password
    // -----------------------------------------------------------------

    public function test_resetting_a_password(): void
    {
        $user = $this->makeUser('viewer', 'forgetful');
        $oldToken = $this->tokenFor($user);
        $url = "/api/users/{$user->id}/reset-password";

        $this->api('POST', $url, ['newPassword' => 'short'], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('newPassword');

        $this->api('POST', $url, ['newPassword' => 'another-long-password'], $this->adminToken)->assertOk();

        $this->login('forgetful', self::PASSWORD)->assertStatus(401);
        $this->login('forgetful', 'another-long-password')->assertOk();
        $this->api('GET', '/api/auth/me', [], $oldToken)->assertStatus(401);

        $this->assertSame(1, DB::table('audit_logs')
            ->where('action', 'reset_password')
            ->where('entity_id', $user->id)
            ->count());
    }

    public function test_an_administrator_cannot_reset_their_own_password_this_way(): void
    {
        $this->api('POST', "/api/users/{$this->actor->id}/reset-password", [
            'newPassword' => 'another-long-password',
        ], $this->adminToken)->assertStatus(422);

        $this->api('POST', '/api/users/999999/reset-password', [
            'newPassword' => 'another-long-password',
        ], $this->adminToken)->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Privacy
    // -----------------------------------------------------------------

    public function test_responses_never_contain_password_data(): void
    {
        $created = $this->api('POST', '/api/users', $this->payload(), $this->adminToken)->assertStatus(201);
        $id = $created->json('id');

        $responses = [
            $created,
            $this->api('GET', "/api/users/{$id}", [], $this->adminToken),
            $this->api('GET', '/api/users', [], $this->adminToken),
        ];

        foreach ($responses as $response) {
            $content = strtolower($response->getContent());
            $this->assertStringNotContainsString('password', $content);
            $this->assertStringNotContainsString('hash', $content);
        }
    }
}
