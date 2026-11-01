<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingSetupService
{
    public const CATEGORIES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** API field => column of the accounting_settings table */
    public const SETTING_COLUMNS = [
        'profitLossAccountId' => 'profit_loss_account_id',
        'retainedEarningsAccountId' => 'retained_earnings_account_id',
        'closingAccountId' => 'closing_account_id',
        'openingAccountId' => 'opening_account_id',
    ];

    /** The top-level groups with their category (null = not classified yet). */
    public function categories(): Collection
    {
        return DB::table('accounts as g')
            ->leftJoin('account_group_categories as c', 'c.account_id', '=', 'g.id')
            ->where('g.level', 1)
            ->orderBy('g.code')
            ->selectRaw(<<<'SQL'
                g.id as "accountId",
                g.code,
                g.name,
                g.is_active as "isActive",
                c.category,
                exists(select 1
                         from journal_lines l
                         join accounts m on m.id = l.account_id
                         join accounts k on k.id = m.parent_id
                        where k.parent_id = g.id) as "hasEntries"
                SQL)
            ->get();
    }

    public function setCategory(int $accountId, string $category, int $actorId): void
    {
        DB::transaction(function () use ($accountId, $category, $actorId) {
            $account = DB::table('accounts')->where('id', $accountId)->first();
            if (! $account) {
                abort(404, 'Account not found');
            }
            if ((int) $account->level !== 1) {
                abort(422, 'Only group accounts (level 1) can be classified; the accounts below a group inherit its category.');
            }

            $old = DB::table('account_group_categories')->where('account_id', $accountId)->value('category');

            DB::table('account_group_categories')->updateOrInsert(
                ['account_id' => $accountId],
                ['category' => $category],
            );

            if ($old !== $category) {
                Audit::log($actorId, 'classify', 'account', $accountId, ['from' => $old, 'to' => $category]);
            }
        });
    }

    /** @return array<string, array{id: int, code: string, name: string, isActive: bool}|null> */
    public function settings(): array
    {
        $row = DB::table('accounting_settings')->where('id', 1)->first();
        $result = [];

        foreach (self::SETTING_COLUMNS as $field => $column) {
            $id = $row?->{$column};
            $result[$field] = $id === null ? null : $this->accountSummary((int) $id);
        }

        return $result;
    }

    /** @param array<string, int|string> $data */
    public function updateSettings(array $data, int $actorId): void
    {
        DB::transaction(function () use ($data, $actorId) {
            $errors = [];
            $usedBy = [];
            $values = [];

            foreach (self::SETTING_COLUMNS as $field => $column) {
                $id = (int) $data[$field];
                $values[$column] = $id;
                $account = DB::table('accounts')->where('id', $id)->first();

                if (! $account || (int) $account->level !== 3 || ! $account->is_postable) {
                    $errors[$field] = 'Must be a movement (level 3) account that accepts entries.';
                } elseif (! $account->is_active) {
                    $errors[$field] = 'Must be an active account.';
                } elseif ($account->requires_detail) {
                    $errors[$field] = 'Must not require a detail account.';
                }

                if (isset($usedBy[$id])) {
                    $errors[$field] = 'Each setting needs its own account.';
                    $errors[$usedBy[$id]] = 'Each setting needs its own account.';
                }
                $usedBy[$id] = $field;
            }

            if (! isset($errors['retainedEarningsAccountId'])) {
                $category = DB::table('accounts as m')
                    ->join('accounts as k', 'k.id', '=', 'm.parent_id')
                    ->join('accounts as g', 'g.id', '=', 'k.parent_id')
                    ->leftJoin('account_group_categories as c', 'c.account_id', '=', 'g.id')
                    ->where('m.id', $values['retained_earnings_account_id'])
                    ->value('c.category');

                if ($category !== 'equity') {
                    $errors['retainedEarningsAccountId'] = 'Must belong to a group classified as equity.';
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $old = (array) DB::table('accounting_settings')->where('id', 1)->first();

            DB::table('accounting_settings')->where('id', 1)->update($values + ['updated_at' => now()]);

            Audit::log($actorId, 'update', 'accounting_settings', null, [
                'from' => array_intersect_key($old, $values),
                'to' => $values,
            ]);
        });
    }

    /** @return array{id: int, code: string, name: string, isActive: bool} */
    private function accountSummary(int $id): array
    {
        $account = DB::table('accounts')->where('id', $id)->first(['id', 'code', 'name', 'is_active']);

        return [
            'id' => (int) $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'isActive' => (bool) $account->is_active,
        ];
    }
}
