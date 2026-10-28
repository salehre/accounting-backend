<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthTest extends TestCase
{
    private function loginAttempt(string $username, string $password): TestResponse
    {
        return $this->api('POST', '/api/auth/token', [
            'username' => $username,
            'password' => $password,
            'device_name' => 'phpunit',
        ]);
    }

    public function test_token_login_returns_a_token_and_the_user_with_permissions(): void
    {
        $this->makeUser('accountant', 'ali');

        $response = $this->loginAttempt('ALI', self::PASSWORD); // usernames are case-insensitive

        $response->assertOk()
            ->assertJsonPath('user.username', 'ali')
            ->assertJsonPath('user.role', 'accountant')
            ->assertJsonStructure(['token', 'user' => ['id', 'username', 'fullName', 'role', 'permissions']]);

        $permissions = $response->json('user.permissions');
        $this->assertContains('entries.post', $permissions);
        $this->assertNotContains('*', $permissions);
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_wrong_password_and_unknown_user_get_the_same_answer(): void
    {
        $this->makeUser('viewer', 'maryam');

        $wrongPassword = $this->loginAttempt('maryam', 'wrong-password-123');
        $unknownUser = $this->loginAttempt('nobody', 'wrong-password-123');

        $wrongPassword->assertStatus(401);
        $unknownUser->assertStatus(401);
        $this->assertSame($wrongPassword->json('message'), $unknownUser->json('message'));
    }

    public function test_a_disabled_user_cannot_log_in(): void
    {
        $this->makeUser('viewer', 'disabled', false);

        $this->loginAttempt('disabled', self::PASSWORD)->assertStatus(401);
    }

    public function test_disabling_a_user_cuts_off_an_existing_token_immediately(): void
    {
        $user = $this->makeUser('viewer');
        $token = $this->tokenFor($user);
        $this->api('GET', '/api/auth/me', [], $token)->assertOk();

        DB::table('users')->where('id', $user->id)->update(['is_active' => false]);

        $this->api('GET', '/api/auth/me', [], $token)->assertStatus(403);
    }

    public function test_five_wrong_passwords_lock_the_account_for_a_while(): void
    {
        $this->makeUser('viewer', 'locked');

        foreach (range(1, 5) as $attempt) {
            $this->loginAttempt('locked', 'wrong-password-123')->assertStatus(401);
        }

        $this->loginAttempt('locked', 'wrong-password-123')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // Even the correct password is refused while the account is locked
        $this->loginAttempt('locked', self::PASSWORD)->assertStatus(429);
    }

    public function test_a_successful_login_resets_the_failed_attempts(): void
    {
        $this->makeUser('viewer', 'resetter');

        foreach (range(1, 4) as $attempt) {
            $this->loginAttempt('resetter', 'wrong-password-123')->assertStatus(401);
        }

        $this->loginAttempt('resetter', self::PASSWORD)->assertOk();

        foreach (range(1, 4) as $attempt) {
            $this->loginAttempt('resetter', 'wrong-password-123')->assertStatus(401);
        }
    }

    public function test_one_ip_cannot_try_an_unlimited_number_of_usernames(): void
    {
        foreach (range(1, 30) as $i) {
            $this->loginAttempt("nobody{$i}", 'wrong-password-123')->assertStatus(401);
        }

        $this->loginAttempt('nobody31', 'wrong-password-123')->assertStatus(429);
    }

    public function test_the_me_endpoint_requires_a_token_and_exposes_only_safe_fields(): void
    {
        $this->api('GET', '/api/auth/me')->assertStatus(401);

        $response = $this->api('GET', '/api/auth/me', [], $this->tokenFor($this->makeUser('viewer')));

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['id', 'username', 'fullName', 'role', 'permissions'],
            array_keys($response->json()),
        );
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->makeUser('viewer');
        $token = $this->tokenFor($user);

        $this->api('POST', '/api/auth/logout', [], $token)->assertOk();

        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->api('GET', '/api/auth/me', [], $token)->assertStatus(401);
    }

    public function test_tokens_expire_after_thirty_days(): void
    {
        $token = $this->tokenFor($this->makeUser('viewer'));

        $this->travel(29)->days();
        $this->api('GET', '/api/auth/me', [], $token)->assertOk();

        $this->travel(2)->days(); // now 31 days after login
        $this->api('GET', '/api/auth/me', [], $token)->assertStatus(401);
    }

    public function test_changing_the_password(): void
    {
        $user = $this->makeUser('viewer', 'changer');
        $token = $this->tokenFor($user);
        $otherDevice = $this->tokenFor($user);

        $change = fn (string $current, string $new) => $this->api(
            'POST',
            '/api/auth/change-password',
            ['currentPassword' => $current, 'newPassword' => $new],
            $token,
        );

        $change('not-the-password', 'a-brand-new-password')
            ->assertStatus(422)->assertJsonValidationErrors('currentPassword');
        $change(self::PASSWORD, 'short')
            ->assertStatus(422)->assertJsonValidationErrors('newPassword');
        $change(self::PASSWORD, str_repeat('x', 73))
            ->assertStatus(422)->assertJsonValidationErrors('newPassword');
        $change(self::PASSWORD, self::PASSWORD)
            ->assertStatus(422)->assertJsonValidationErrors('newPassword');

        $change(self::PASSWORD, 'a-brand-new-password')->assertOk();

        // The old password stops working, the new one works
        $this->loginAttempt('changer', self::PASSWORD)->assertStatus(401);
        $this->loginAttempt('changer', 'a-brand-new-password')->assertOk();

        // This device stays logged in, the other device is signed out
        $this->api('GET', '/api/auth/me', [], $token)->assertOk();
        $this->api('GET', '/api/auth/me', [], $otherDevice)->assertStatus(401);
    }

    public function test_logins_are_written_to_the_audit_log(): void
    {
        $user = $this->makeUser('viewer', 'audited');

        $this->loginAttempt('audited', 'wrong-password-123')->assertStatus(401);
        $this->loginAttempt('audited', self::PASSWORD)->assertOk();

        $actions = DB::table('audit_logs')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(['login_failed', 'login'], $actions);
    }

    public function test_the_session_login_refuses_non_browser_clients(): void
    {
        $this->makeUser('viewer', 'browseruser');

        $this->api('POST', '/api/auth/login', [
            'username' => 'browseruser',
            'password' => self::PASSWORD,
        ])->assertStatus(400);
    }
}
