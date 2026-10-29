<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class UserService
{
    private const SELECT = 'u.id,
                            u.username,
                            u.full_name     as "fullName",
                            r.id            as "roleId",
                            r.name          as role,
                            u.is_active     as "isActive",
                            u.last_login_at as "lastLoginAt",
                            u.created_at    as "createdAt"';

    /** @return array{items: \Illuminate\Support\Collection, page: int, pageSize: int, total: int} */
    public function paginate(?string $search, ?bool $isActive, int $page, int $pageSize): array
    {
        $query = DB::table('users as u')
            ->join('roles as r', 'r.id', '=', 'u.role_id')
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                // escape the characters that have a special meaning inside LIKE
                $like = '%'.addcslashes(mb_strtolower((string) $search), '\\%_').'%';

                $q->where(function ($q) use ($like) {
                    $q->whereRaw('lower(u.username) like ?', [$like])
                        ->orWhereRaw('lower(u.full_name) like ?', [$like]);
                });
            })
            ->when($isActive !== null, fn ($q) => $q->where('u.is_active', $isActive));

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('u.id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->selectRaw(self::SELECT)
            ->get();

        return [
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $user = DB::table('users as u')
            ->join('roles as r', 'r.id', '=', 'u.role_id')
            ->where('u.id', $id)
            ->selectRaw(self::SELECT)
            ->first();

        if (! $user) {
            abort(404, 'User not found');
        }

        return (array) $user;
    }

    /** @param array{username: string, fullName: string, password: string, roleId: int|string} $data */
    public function create(array $data, int $actorId): int
    {
        return DB::transaction(function () use ($data, $actorId) {
            $id = (int) DB::table('users')->insertGetId([
                'username' => $data['username'],
                'full_name' => $data['fullName'],
                'password_hash' => Hash::make($data['password']),
                'role_id' => (int) $data['roleId'],
            ]);

            Audit::log($actorId, 'create', 'user', $id, [
                'username' => $data['username'],
                'roleId' => (int) $data['roleId'],
            ]);

            return $id;
        });
    }

    /** @param array{fullName?: string, roleId?: int|string, isActive?: mixed} $data */
    public function update(int $id, array $data, int $actorId): void
    {
        DB::transaction(function () use ($id, $data, $actorId) {
            // Lock the row so two administrators cannot change the same user at once
            $user = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            if (! $user) {
                abort(404, 'User not found');
            }

            $changes = [];
            if (array_key_exists('fullName', $data)) {
                $changes['full_name'] = $data['fullName'];
            }
            if (array_key_exists('roleId', $data)) {
                $changes['role_id'] = (int) $data['roleId'];
            }
            if (array_key_exists('isActive', $data)) {
                $changes['is_active'] = filter_var($data['isActive'], FILTER_VALIDATE_BOOLEAN);
            }

            $newRoleId = $changes['role_id'] ?? (int) $user->role_id;
            $newActive = $changes['is_active'] ?? (bool) $user->is_active;

            $roleOrStatusChanges = $newRoleId !== (int) $user->role_id
                || $newActive !== (bool) $user->is_active;

            if ($id === $actorId && $roleOrStatusChanges) {
                abort(422, 'You cannot change your own role or disable your own account.');
            }

            $this->assertAnAdminRemains($user, $newRoleId, $newActive);

            $diff = $this->describeChanges($user, $changes);
            if ($diff === []) {
                return; // nothing really changed
            }

            DB::table('users')->where('id', $id)->update($changes);

            if (! $newActive) {
                $this->revokeTokens($id);
            }

            Audit::log($actorId, 'update', 'user', $id, ['changes' => $diff]);
        });
    }

    public function resetPassword(int $id, string $newPassword, int $actorId): void
    {
        if ($id === $actorId) {
            abort(422, 'Use the change-password feature for your own account.');
        }

        DB::transaction(function () use ($id, $newPassword, $actorId) {
            $updated = DB::table('users')
                ->where('id', $id)
                ->update(['password_hash' => Hash::make($newPassword)]);

            if ($updated === 0) {
                abort(404, 'User not found');
            }

            $this->revokeTokens($id);

            Audit::log($actorId, 'reset_password', 'user', $id);
        });
    }

    /** Refuses a change that would leave the system without an active administrator. */
    private function assertAnAdminRemains(object $user, int $newRoleId, bool $newActive): void
    {
        $adminRoleId = (int) DB::table('roles')->where('name', 'admin')->value('id');

        $wasActiveAdmin = (int) $user->role_id === $adminRoleId && (bool) $user->is_active;
        $staysActiveAdmin = $newRoleId === $adminRoleId && $newActive;

        if (! $wasActiveAdmin || $staysActiveAdmin) {
            return;
        }

        $otherAdmins = DB::table('users')
            ->where('role_id', $adminRoleId)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($otherAdmins->isEmpty()) {
            abort(422, 'At least one active administrator must remain.');
        }
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function describeChanges(object $user, array $changes): array
    {
        $labels = ['full_name' => 'fullName', 'role_id' => 'roleId', 'is_active' => 'isActive'];
        $diff = [];

        foreach ($changes as $column => $new) {
            $old = $user->{$column};

            if ($old != $new) {
                $diff[$labels[$column]] = ['from' => $old, 'to' => $new];
            }
        }

        return $diff;
    }

    private function revokeTokens(int $userId): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();
    }
}
