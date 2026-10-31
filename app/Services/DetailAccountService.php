<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class DetailAccountService
{
    /** Must match the CHECK constraint on detail_accounts.kind. */
    public const KINDS = ['customer', 'supplier', 'employee', 'bank', 'cash', 'cost_center', 'other'];

    private const SELECT = 'd.id,
                            d.code,
                            d.name,
                            d.kind,
                            d.is_active as "isActive",
                            exists(select 1 from journal_lines l where l.detail_id = d.id) as "hasEntries"';

    private const COLUMN_LABELS = [
        'name' => 'name',
        'code' => 'code',
        'kind' => 'kind',
        'is_active' => 'isActive',
    ];

    public function search(?string $search, ?string $kind, bool $includeInactive, int $limit): Collection
    {
        return DB::table('detail_accounts as d')
            ->when(! $includeInactive, fn ($q) => $q->where('d.is_active', true))
            ->when($kind !== null, fn ($q) => $q->where('d.kind', $kind))
            ->when($search !== null && $search !== '', function ($q) use ($search) {
                // escape the characters that have a special meaning inside LIKE
                $like = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';

                $q->where(function ($q) use ($like) {
                    $q->whereRaw('lower(d.code) like ?', [$like])
                        ->orWhereRaw('lower(d.name) like ?', [$like]);
                });
            })
            ->orderBy('d.code')
            ->limit($limit)
            ->selectRaw(self::SELECT)
            ->get();
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $detail = DB::table('detail_accounts as d')
            ->where('d.id', $id)
            ->selectRaw(self::SELECT)
            ->first();

        if (! $detail) {
            abort(404, 'Detail account not found');
        }

        return (array) $detail;
    }

    /** @param array{code: string, name: string, kind?: string|null} $data */
    public function create(array $data, int $actorId): int
    {
        return DB::transaction(function () use ($data, $actorId) {
            $id = (int) DB::table('detail_accounts')->insertGetId([
                'code' => $data['code'],
                'name' => $data['name'],
                'kind' => $data['kind'] ?? 'other',
            ]);

            Audit::log($actorId, 'create', 'detail_account', $id, [
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            return $id;
        });
    }

    /** @param array{name?: string, code?: string, kind?: string, isActive?: mixed} $data */
    public function update(int $id, array $data, int $actorId): void
    {
        DB::transaction(function () use ($id, $data, $actorId) {
            $detail = DB::table('detail_accounts')->where('id', $id)->lockForUpdate()->first();
            if (! $detail) {
                abort(404, 'Detail account not found');
            }

            $wanted = [];
            if (array_key_exists('name', $data)) {
                $wanted['name'] = (string) $data['name'];
            }
            if (array_key_exists('code', $data)) {
                $wanted['code'] = (string) $data['code'];
            }
            if (array_key_exists('kind', $data)) {
                $wanted['kind'] = (string) $data['kind'];
            }
            if (array_key_exists('isActive', $data)) {
                $wanted['is_active'] = filter_var($data['isActive'], FILTER_VALIDATE_BOOLEAN);
            }

            $diff = [];
            foreach ($wanted as $column => $new) {
                if ($this->differs($detail->{$column}, $new)) {
                    $diff[$column] = $new;
                }
            }
            if ($diff === []) {
                return; // nothing really changed
            }

            if (isset($diff['code']) && $this->isInUse($id)) {
                abort(422, 'This detail account is already in use: its code can no longer be changed.');
            }

            DB::table('detail_accounts')->where('id', $id)->update($diff);

            $changes = [];
            foreach ($diff as $column => $new) {
                $changes[self::COLUMN_LABELS[$column]] = ['from' => $detail->{$column}, 'to' => $new];
            }
            Audit::log($actorId, 'update', 'detail_account', $id, ['changes' => $changes]);
        });
    }

    public function delete(int $id, int $actorId): void
    {
        DB::transaction(function () use ($id, $actorId) {
            $detail = DB::table('detail_accounts')->where('id', $id)->lockForUpdate()->first();
            if (! $detail) {
                abort(404, 'Detail account not found');
            }

            $message = 'This detail account is in use. Disable it instead of deleting it.';

            if ($this->isInUse($id)) {
                abort(409, $message);
            }

            try {
                DB::table('detail_accounts')->where('id', $id)->delete();
            } catch (QueryException $e) {
                // foreign key violation: something started using it meanwhile
                if (($e->errorInfo[0] ?? '') === '23503') {
                    abort(409, $message);
                }

                throw $e;
            }

            Audit::log($actorId, 'delete', 'detail_account', $id, [
                'code' => $detail->code,
                'name' => $detail->name,
            ]);
        });
    }

    private function isInUse(int $id): bool
    {
        return DB::table('journal_lines')->where('detail_id', $id)->exists();
    }

    private function differs(mixed $old, mixed $new): bool
    {
        return is_bool($new) ? (bool) $old !== $new : (string) $old !== (string) $new;
    }
}
