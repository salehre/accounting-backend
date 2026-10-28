<?php

namespace Tests;

use App\Models\Role;
use App\Models\User;
use App\Services\JournalEntryService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Password of every user created by makeUser(). */
    protected const PASSWORD = 'correct-horse-battery';

    private static bool $schemaReady = false;

    /** An admin user, used as "created by" for entries made directly through the service. */
    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refuseToRunOnTheWrongDatabase();
        $this->buildSchemaOnce();

        // Every test runs inside a transaction that is rolled back at the end,
        // so tests never see each other's data.
        DB::beginTransaction();

        $this->actor = $this->makeUser('admin');
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    private function refuseToRunOnTheWrongDatabase(): void
    {
        $database = (string) DB::connection()->getDatabaseName();

        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException(
                "Refusing to run tests on database \"{$database}\": the test database name must end with \"_test\"."
            );
        }
    }

    private function buildSchemaOnce(): void
    {
        if (self::$schemaReady) {
            return;
        }

        // Start from an empty schema. migrate:fresh would leave our SQL
        // functions behind and the next run would fail.
        DB::unprepared('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        Artisan::call('migrate', ['--force' => true]);

        self::$schemaReady = true;
    }

    // -----------------------------------------------------------------
    // HTTP
    // -----------------------------------------------------------------

    /** Sends a JSON request, optionally with a bearer token. */
    protected function api(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        // Tests reuse one application between requests: forget the cached user.
        $this->app['auth']->forgetGuards();

        $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

        return $this->json($method, $uri, $data, $headers);
    }

    protected function makeUser(string $role = 'viewer', ?string $username = null, bool $active = true): User
    {
        return User::forceCreate([
            'username' => $username ?? 'user'.bin2hex(random_bytes(4)),
            'full_name' => 'Test '.$role,
            'password_hash' => Hash::make(self::PASSWORD),
            'role_id' => Role::query()->where('name', $role)->value('id'),
            'is_active' => $active,
        ]);
    }

    protected function tokenFor(User $user, string $password = self::PASSWORD): string
    {
        $response = $this->api('POST', '/api/auth/token', [
            'username' => $user->username,
            'password' => $password,
            'device_name' => 'phpunit',
        ]);
        $response->assertOk();

        return (string) $response->json('token');
    }

    // -----------------------------------------------------------------
    // Accounting fixtures
    // -----------------------------------------------------------------

    /**
     * Creates an open fiscal year and a small chart of accounts.
     *
     * @return array{fiscalYear:int, group:int, cash:int, bank:int, receivable:int, detail:int}
     */
    protected function makeLedger(): array
    {
        $fiscalYear = DB::table('fiscal_years')->insertGetId([
            'title' => '1405',
            'start_date' => '2026-03-21',
            'end_date' => '2027-03-20',
        ]);

        $group = $this->makeAccount('1', 'Assets', 1, 'debit');
        $current = $this->makeAccount('11', 'Current assets', 2, 'debit', $group);

        return [
            'fiscalYear' => $fiscalYear,
            'group' => $group,
            'cash' => $this->makeAccount('1101', 'Cash', 3, 'debit', $current, true),
            'bank' => $this->makeAccount('1102', 'Bank', 3, 'debit', $current, true),
            'receivable' => $this->makeAccount('1103', 'Customers', 3, 'debit', $current, true, true),
            'detail' => DB::table('detail_accounts')->insertGetId([
                'code' => '100001',
                'name' => 'Sample customer',
                'kind' => 'customer',
            ]),
        ];
    }

    protected function makeAccount(
        string $code,
        string $name,
        int $level,
        string $nature,
        ?int $parentId = null,
        bool $postable = false,
        bool $requiresDetail = false,
    ): int {
        return DB::table('accounts')->insertGetId([
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $name,
            'level' => $level,
            'nature' => $nature,
            'is_postable' => $postable,
            'requires_detail' => $requiresDetail,
        ]);
    }

    /**
     * Creates a draft entry directly through the service.
     *
     * @param array{fiscalYear:int, cash:int, bank:int} $ledger
     * @param array<int, array{accountId:int, detailId?:int, debit?:int, credit?:int}> $lines
     */
    protected function draftEntry(array $ledger, array $lines, string $date = '2026-10-28', ?int $fiscalYearId = null): int
    {
        $lines = array_map(fn (array $line) => $line + ['debit' => 0, 'credit' => 0], $lines);

        return app(JournalEntryService::class)->createDraft([
            'fiscalYearId' => $fiscalYearId ?? $ledger['fiscalYear'],
            'entryDate' => $date,
            'description' => null,
            'lines' => $lines,
        ], $this->actor->id);
    }

    /** A draft that moves $amount from the bank to the cash account. */
    protected function balancedDraft(array $ledger, int $amount = 1000, string $date = '2026-10-28'): int
    {
        return $this->draftEntry($ledger, [
            ['accountId' => $ledger['cash'], 'debit' => $amount],
            ['accountId' => $ledger['bank'], 'credit' => $amount],
        ], $date);
    }

    /**
     * Asserts that the database refuses the operation with the given SQLSTATE
     * (and, optionally, a message fragment).
     */
    protected function assertDatabaseRejects(Closure $operation, string $sqlState, ?string $messagePart = null): void
    {
        try {
            // The savepoint keeps the surrounding test transaction usable after the error.
            DB::transaction($operation);
        } catch (QueryException $e) {
            $this->assertSame($sqlState, (string) ($e->errorInfo[0] ?? ''), $e->getMessage());

            if ($messagePart !== null) {
                $this->assertStringContainsString($messagePart, $e->getMessage());
            }

            return;
        }

        $this->fail("The database accepted an operation that must be rejected (expected SQLSTATE {$sqlState}).");
    }
}
