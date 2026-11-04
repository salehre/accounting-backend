<?php

namespace App\Services\Reports;

use App\Support\ReportScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Trial balance from the daily totals: opening balance, movement of the period and closing balance,
 * per account (level 1, 2 or 3), optionally per detail account.
 */
final class TrialBalanceReport
{
    private const MAX_ROWS = 20000;

    /** @return array<string, mixed> */
    public function generate(
        int $fiscalYearId,
        ?string $from,
        ?string $to,
        int $level,
        bool $withDetails,
        ?int $accountId,
        bool $includeClosing,
        bool $hideZero,
    ): array {
        $scope = ReportScope::resolve($fiscalYearId, $from, $to);
        $from = $scope['from'];
        $to = $scope['to'];

        if ($withDetails && $level !== 3) {
            throw ValidationException::withMessages(['withDetails' => 'Detail accounts can only be shown at level 3.']);
        }

        // m = movement account (level 3), k = its kol (level 2), g = its group (level 1)
        $alias = [1 => 'g', 2 => 'k', 3 => 'm'][$level];

        $select = "{$alias}.id as \"accountId\", {$alias}.code, {$alias}.name";
        $group = "{$alias}.id, {$alias}.code, {$alias}.name";
        $order = "{$alias}.code";
        $detailJoin = '';

        if ($withDetails) {
            $select .= ', t.detail_id as "detailId", d.code as "detailCode", d.name as "detailName"';
            $group .= ', t.detail_id, d.code, d.name';
            $order .= ', t.detail_id';
            $detailJoin = 'left join detail_accounts d on d.id = t.detail_id';
        }

        // "Hide zero" reads only the accounts that have movements (inner join, as fast as it can be).
        // Otherwise every movement account is listed, including the ones that were never used.
        $joinType = $hideZero ? 'join' : 'left join';
        $totalsFilter = 't.account_id = m.id and t.fiscal_year_id = ? and t.entry_date <= ?'
            .($includeClosing ? '' : ' and not t.closing');

        // the order of the placeholders: the four sums, then the totals filter, then the where clause
        $bindings = [$from, $from, $from, $to, $from, $to, $fiscalYearId, $to];
        $where = " and {$alias}.id is not null";

        if ($accountId !== null) {
            $where .= ' and (m.id = ? or k.id = ? or g.id = ?)';
            array_push($bindings, $accountId, $accountId, $accountId);
        }

        $rows = DB::select(
            "select {$select},
                    coalesce(sum(t.debit)  filter (where t.entry_date < ?), 0)                as open_debit,
                    coalesce(sum(t.credit) filter (where t.entry_date < ?), 0)                as open_credit,
                    coalesce(sum(t.debit)  filter (where t.entry_date between ? and ?), 0)    as period_debit,
                    coalesce(sum(t.credit) filter (where t.entry_date between ? and ?), 0)    as period_credit
               from accounts m
               left join accounts k on k.id = m.parent_id
               left join accounts g on g.id = k.parent_id
               {$joinType} account_daily_totals t on {$totalsFilter}
               {$detailJoin}
              where m.level = 3 {$where}
              group by {$group}
              order by {$order}
              limit ".(self::MAX_ROWS + 1),
            $bindings,
        );

        if (count($rows) > self::MAX_ROWS) {
            abort(422, 'The report has more than '.self::MAX_ROWS.' rows. Narrow it down by account or by date.');
        }

        $result = [];
        $totals = array_fill_keys(
            ['openingDebit', 'openingCredit', 'periodDebit', 'periodCredit', 'closingDebit', 'closingCredit'],
            0,
        );

        foreach ($rows as $row) {
            $openNet = (int) $row->open_debit - (int) $row->open_credit;
            $periodDebit = (int) $row->period_debit;
            $periodCredit = (int) $row->period_credit;
            $closeNet = $openNet + $periodDebit - $periodCredit;

            if ($hideZero && $openNet === 0 && $periodDebit === 0 && $periodCredit === 0 && $closeNet === 0) {
                continue;
            }

            $item = [
                'accountId' => (int) $row->accountId,
                'code' => $row->code,
                'name' => $row->name,
                'level' => $level,
            ];
            if ($withDetails) {
                $detailId = (int) ($row->detailId ?? 0);
                $item['detailId'] = $detailId === 0 ? null : $detailId;
                $item['detailCode'] = $row->detailCode;
                $item['detailName'] = $row->detailName;
            }

            $item += [
                'openingDebit' => max($openNet, 0),
                'openingCredit' => max(-$openNet, 0),
                'periodDebit' => $periodDebit,
                'periodCredit' => $periodCredit,
                'closingDebit' => max($closeNet, 0),
                'closingCredit' => max(-$closeNet, 0),
            ];

            foreach ($totals as $key => $_) {
                $totals[$key] += $item[$key];
            }
            $result[] = $item;
        }

        // Only a report of ALL accounts has to balance
        $balanced = $accountId !== null ? null
            : $totals['openingDebit'] === $totals['openingCredit']
            && $totals['periodDebit'] === $totals['periodCredit']
            && $totals['closingDebit'] === $totals['closingCredit'];

        return [
            'fiscalYear' => ['id' => (int) $scope['year']->id, 'title' => $scope['year']->title],
            'from' => $from,
            'to' => $to,
            'level' => $level,
            'includeClosing' => $includeClosing,
            'rows' => $result,
            'totals' => $totals,
            'balanced' => $balanced,
        ];
    }
}
