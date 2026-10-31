<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountService
{
    private const SELECT = 'a.id,
                            a.parent_id       as "parentId",
                            a.code,
                            a.name,
                            a.level,
                            a.nature,
                            a.is_postable     as "isPostable",
                            a.requires_detail as "requiresDetail",
                            a.is_active       as "isActive",
                            exists(select 1 from accounts c where c.parent_id = a.id)       as "hasChildren",
                            exists(select 1 from journal_lines l where l.account_id = a.id) as "hasEntries"';

    private const COLUMN_LABELS = [
        'name' => 'name',
        'code' => 'code',
        'nature' => 'nature',
        'requires_detail' => 'requiresDetail',
        'is_active' => 'isActive',
    ];

    public function all(bool $activeOnly): Collection
    {
        return DB::table('accounts as a')
            ->when($activeOnly, fn ($q) => $q->where('a.is_active', true))
            ->orderBy('a.code')
            ->selectRaw(self::SELECT)
            ->get();
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $account = DB::table('accounts as a')
            ->where('a.id', $id)
            ->selectRaw(self::SELECT)
            ->first();

        if (! $account) {
            abort(404, 'Account not found');
        }

        return (array) $account;
    }

    /**
     * @param array{level: int|string, parentId?: int|string|null, code: string, name: string, nature: string, requiresDetail?: mixed} $data
     */
    public function create(array $data, int $actorId): int
    {
        return DB::transaction(function () use ($data, $actorId) {
            $level = (int) $data['level'];
            $parentId = isset($data['parentId']) ? (int) $data['parentId'] : null;
            $code = (string) $data['code'];

            if ($level === 1) {
                if ($parentId !== null) {
                    $this->invalid('parentId', 'A group account (level 1) cannot have a parent.');
                }
            } else {
                if ($parentId === null) {
                    $this->invalid('parentId', "A level {$level} account needs a parent account.");
                }

                // Lock the parent so it cannot be changed or deleted while we add a child
                $parent = DB::table('accounts')->where('id', $parentId)->lockForUpdate()->first();
                if (! $parent) {
                    $this->invalid('parentId', 'Parent account not found.');
                }
                if ((int) $parent->level !== $level - 1) {
                    $this->invalid('parentId', "The parent of a level {$level} account must be a level ".($level - 1).' account.');
                }
                if (! $parent->is_active) {
                    $this->invalid('parentId', 'The parent account is disabled.');
                }

                $this->assertCodeFollowsParent($code, (string) $parent->code);
            }

            $requiresDetail = filter_var($data['requiresDetail'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($requiresDetail && $level !== 3) {
                $this->invalid('requiresDetail', 'Only level 3 accounts can require a detail account.');
            }

            $id = (int) DB::table('accounts')->insertGetId([
                'parent_id' => $parentId,
                'code' => $code,
                'name' => $data['name'],
                'level' => $level,
                'nature' => $data['nature'],
                'is_postable' => $level === 3, // only level 3 accounts can receive entries
                'requires_detail' => $requiresDetail,
            ]);

            Audit::log($actorId, 'create', 'account', $id, [
                'code' => $code,
                'name' => $data['name'],
                'level' => $level,
            ]);

            return $id;
        });
    }

    /** @param array{name?: string, code?: string, nature?: string, requiresDetail?: mixed, isActive?: mixed} $data */
    public function update(int $id, array $data, int $actorId): void
    {
        DB::transaction(function () use ($id, $data, $actorId) {
            // Locking the row also makes a concurrent "add entry line" wait for us
            $account = DB::table('accounts')->where('id', $id)->lockForUpdate()->first();
            if (! $account) {
                abort(404, 'Account not found');
            }

            $wanted = [];
            if (array_key_exists('name', $data)) {
                $wanted['name'] = (string) $data['name'];
            }
            if (array_key_exists('code', $data)) {
                $wanted['code'] = (string) $data['code'];
            }
            if (array_key_exists('nature', $data)) {
                $wanted['nature'] = (string) $data['nature'];
            }
            if (array_key_exists('requiresDetail', $data)) {
                $wanted['requires_detail'] = filter_var($data['requiresDetail'], FILTER_VALIDATE_BOOLEAN);
            }
            if (array_key_exists('isActive', $data)) {
                $wanted['is_active'] = filter_var($data['isActive'], FILTER_VALIDATE_BOOLEAN);
            }

            $diff = [];
            foreach ($wanted as $column => $new) {
                if ($this->differs($account->{$column}, $new)) {
                    $diff[$column] = $new;
                }
            }
            if ($diff === []) {
                return; // nothing really changed
            }

            $structural = array_intersect_key($diff, ['code' => 1, 'nature' => 1, 'requires_detail' => 1]);
            if ($structural !== [] && $this->isInUse($id)) {
                abort(422, 'This account is already in use: only its name and active status can be changed.');
            }

            if (($diff['requires_detail'] ?? false) === true && (int) $account->level !== 3) {
                $this->invalid('requiresDetail', 'Only level 3 accounts can require a detail account.');
            }

            if (isset($diff['code']) && $account->parent_id !== null) {
                $parentCode = (string) DB::table('accounts')->where('id', $account->parent_id)->value('code');
                $this->assertCodeFollowsParent($diff['code'], $parentCode);
            }

            if (array_key_exists('is_active', $diff)) {
                if ($diff['is_active'] === false) {
                    $hasActiveChild = DB::table('accounts')
                        ->where('parent_id', $id)
                        ->where('is_active', true)
                        ->exists();
                    if ($hasActiveChild) {
                        abort(422, 'Disable the child accounts first.');
                    }
                } elseif ($account->parent_id !== null
                    && ! DB::table('accounts')->where('id', $account->parent_id)->value('is_active')) {
                    abort(422, 'Enable the parent account first.');
                }
            }

            DB::table('accounts')->where('id', $id)->update($diff);

            $changes = [];
            foreach ($diff as $column => $new) {
                $changes[self::COLUMN_LABELS[$column]] = ['from' => $account->{$column}, 'to' => $new];
            }
            Audit::log($actorId, 'update', 'account', $id, ['changes' => $changes]);
        });
    }

    public function delete(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId) {
            $account = DB::table('accounts')->where('id', $id)->lockForUpdate()->first();
            if (! $account) {
                abort(404, 'Account not found');
            }

            $message = 'This account is in use. Disable it instead of deleting it.';

            if ($this->isInUse($id)) {
                abort(409, $message);
            }

            try {
                DB::table('accounts')->where('id', $id)->delete();
            } catch (QueryException $e) {
                // foreign key violation: something started using the account meanwhile
                if (($e->errorInfo[0] ?? '') === '23503') {
                    abort(409, $message);
                }

                throw $e;
            }

            Audit::log($actorId, 'delete', 'account', $id, [
                'code' => $account->code,
                'name' => $account->name,
            ]);
        });
    }

    /** An account is "in use" when it has child accounts or journal lines. */
    private function isInUse(int $id): bool
    {
        return DB::table('accounts')->where('parent_id', $id)->exists()
            || DB::table('journal_lines')->where('account_id', $id)->exists();
    }

    private function assertCodeFollowsParent(string $code, string $parentCode): void
    {
        if (strlen($code) <= strlen($parentCode) || ! str_starts_with($code, $parentCode)) {
            $this->invalid('code', "The code must start with the parent code ({$parentCode}) and be longer than it.");
        }
    }

    private function differs(mixed $old, mixed $new): bool
    {
        // strict comparison: "1e3" and "1000" are different codes
        return is_bool($new) ? (bool) $old !== $new : (string) $old !== (string) $new;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
