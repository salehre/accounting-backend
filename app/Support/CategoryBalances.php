<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Net balances (debit - credit) per account, read from the daily totals. The year-end closing
 * entries are never part of it: the income statement and the balance sheet always show the
 * figures BEFORE the closing. Accounts with a zero net balance are left out.
 */
final class CategoryBalances
{
    /**
     * @param string|null $from first day (null = from the start of the fiscal year)
     * @return array<int, array{accountId: int, code: string, name: string, category: ?string, net: int}>
     */
    public static function between(int $fiscalYearId, ?string $from, string $to, int $level): array
    {
        // m = movement account (level 3), k = its kol (level 2), g = its group (level 1)
        $alias = [1 => 'g', 2 => 'k', 3 => 'm'][$level];

        $bindings = [$fiscalYearId, $to];
        $fromSql = '';
        if ($from !== null) {
            $fromSql = ' and t.entry_date >= ?';
            $bindings[] = $from;
        }

        $rows = DB::select(
            "select {$alias}.id as \"accountId\", {$alias}.code, {$alias}.name, c.category,
                    sum(t.debit) - sum(t.credit) as net
               from account_daily_totals t
               join accounts m on m.id = t.account_id
               left join accounts k on k.id = m.parent_id
               left join accounts g on g.id = k.parent_id
               left join account_group_categories c on c.account_id = g.id
              where t.fiscal_year_id = ? and not t.closing and t.entry_date <= ? {$fromSql}
              group by {$alias}.id, {$alias}.code, {$alias}.name, c.category
             having sum(t.debit) - sum(t.credit) <> 0
              order by {$alias}.code",
            $bindings,
        );

        $result = [];
        foreach ($rows as $row) {
            if ($row->accountId === null) {
                continue;
            }

            $result[] = [
                'accountId' => (int) $row->accountId,
                'code' => $row->code,
                'name' => $row->name,
                'category' => $row->category,
                'net' => (int) $row->net,
            ];
        }

        return $result;
    }
}
