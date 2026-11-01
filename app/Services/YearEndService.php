<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Works out the year-end entries of a fiscal year WITHOUT saving anything.
 *
 * Four entries:
 *   temporary - closes revenue and expense accounts against the profit & loss account
 *   profit    - moves the profit (or loss) to retained earnings
 *   closing   - closes asset, liability and equity accounts against the closing account
 *   opening   - recreates those balances on the first day of the next fiscal year
 *
 * Balances come from the POSTED entries of the fiscal year. Every account is closed
 * per (account, detail account) pair, so accounts that require a detail account stay valid.
 */
final class YearEndService
{
    private const TEMPORARY = ['revenue', 'expense'];

    /** @return array<string, mixed> */
    public function preview(int $fiscalYearId): array
    {
        $year = DB::table('fiscal_years')->where('id', $fiscalYearId)->first();
        if (! $year) {
            abort(404, 'Fiscal year not found');
        }

        $settings = (array) (DB::table('accounting_settings')->where('id', 1)->first() ?? []);
        $blockers = [];
        $warnings = [];

        // ---- settings -------------------------------------------------
        $missing = [];
        foreach (AccountingSetupService::SETTING_COLUMNS as $field => $column) {
            if (empty($settings[$column])) {
                $missing[] = $field;
            }
        }
        $settingsReady = $missing === [];

        if (! $settingsReady) {
            $blockers[] = [
                'code' => 'settings_missing',
                'message' => 'Choose the profit and loss, retained earnings, closing and opening accounts first.',
                'fields' => $missing,
            ];
        }

        $technicalIds = [];
        if ($settingsReady) {
            $allIds = array_map(fn (string $column) => (int) $settings[$column], array_values(AccountingSetupService::SETTING_COLUMNS));
            $technicalIds = [
                (int) $settings['profit_loss_account_id'],
                (int) $settings['closing_account_id'],
                (int) $settings['opening_account_id'],
            ];

            $disabled = DB::table('accounts')->whereIn('id', $allIds)->where('is_active', false)->pluck('code')->all();
            if ($disabled !== []) {
                $blockers[] = [
                    'code' => 'settings_invalid',
                    'message' => 'These technical accounts are disabled: '.implode(', ', $disabled).'.',
                    'accounts' => $disabled,
                ];
            }
        }

        // ---- the fiscal years and drafts ------------------------------
        if ($year->status === 'closed') {
            $blockers[] = [
                'code' => 'year_closed',
                'message' => 'This fiscal year is closed. Reopen it before generating year-end entries.',
            ];
        }

        $draftCount = DB::table('journal_entries')
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', 'draft')
            ->count();
        if ($draftCount > 0) {
            $blockers[] = [
                'code' => 'draft_entries',
                'message' => "{$draftCount} draft entr".($draftCount === 1 ? 'y' : 'ies').' must be posted or deleted first.',
                'count' => $draftCount,
            ];
        }

        $next = DB::table('fiscal_years')
            ->where('start_date', '>', $year->end_date)
            ->orderBy('start_date')
            ->first(['id', 'title', 'start_date', 'status']);

        if (! $next) {
            $blockers[] = [
                'code' => 'next_year_missing',
                'message' => 'Create the next fiscal year first: the opening entry belongs to it.',
            ];
        } elseif ($next->status === 'closed') {
            $blockers[] = [
                'code' => 'next_year_closed',
                'message' => "The next fiscal year (\"{$next->title}\") is closed.",
            ];
        } else {
            $days = (int) DB::selectOne('select (?::date - ?::date) as days', [$next->start_date, $year->end_date])->days;
            if ($days > 1) {
                $warnings[] = [
                    'code' => 'gap_before_next_year',
                    'message' => 'There is a gap of '.($days - 1)." day(s) before the next fiscal year (\"{$next->title}\"). The opening entry is dated {$next->start_date}.",
                ];
            }
        }

        // ---- balances of the posted entries ---------------------------
        $rows = DB::select(<<<'SQL'
            select l.account_id as "accountId",
                   l.detail_id  as "detailId",
                   sum(l.debit) - sum(l.credit) as balance,
                   g.id         as "groupId",
                   c.category
              from journal_lines l
              join journal_entries e on e.id = l.entry_id
                                    and e.status = 'posted'
                                    and e.fiscal_year_id = ?
              join accounts m on m.id = l.account_id
              left join accounts k on k.id = m.parent_id
              left join accounts g on g.id = k.parent_id
              left join account_group_categories c on c.account_id = g.id
             group by l.account_id, l.detail_id, g.id, c.category
            having sum(l.debit) - sum(l.credit) <> 0
            SQL, [$fiscalYearId]);

        // The technical accounts must end with a zero balance: they are only used to balance
        // the year-end entries themselves.
        if ($technicalIds !== []) {
            $notZero = DB::table('journal_lines as l')
                ->join('journal_entries as e', function ($join) use ($fiscalYearId) {
                    $join->on('e.id', '=', 'l.entry_id')
                        ->where('e.status', 'posted')
                        ->where('e.fiscal_year_id', $fiscalYearId);
                })
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->whereIn('l.account_id', $technicalIds)
                ->groupBy('l.account_id', 'a.code')
                ->havingRaw('sum(l.debit) - sum(l.credit) <> 0')
                ->selectRaw('l.account_id as "accountId", a.code, sum(l.debit) - sum(l.credit) as balance')
                ->get()
                ->map(fn ($row) => ['accountId' => (int) $row->accountId, 'code' => $row->code, 'balance' => (int) $row->balance])
                ->all();

            if ($notZero !== []) {
                $blockers[] = [
                    'code' => 'technical_accounts_not_zero',
                    'message' => 'The profit and loss, closing and opening accounts must have a zero balance. Fix the entries that use them.',
                    'accounts' => $notZero,
                ];
            }
        }

        $items = [];
        $unclassifiedGroups = [];
        foreach ($rows as $row) {
            $accountId = (int) $row->accountId;
            if (in_array($accountId, $technicalIds, true)) {
                continue;
            }
            if ($row->category === null) {
                $unclassifiedGroups[(int) ($row->groupId ?? 0)] = true;

                continue;
            }

            $items[] = [
                'account' => $accountId,
                'detail' => $row->detailId === null ? null : (int) $row->detailId,
                'balance' => (int) $row->balance,
                'category' => $row->category,
            ];
        }

        if ($unclassifiedGroups !== []) {
            $blockers[] = [
                'code' => 'unclassified_accounts',
                'message' => 'These account groups have balances but no category (asset, liability, equity, revenue or expense). Classify them first.',
                'groups' => DB::table('accounts')
                    ->whereIn('id', array_keys($unclassifiedGroups))
                    ->orderBy('code')
                    ->get(['id', 'code', 'name'])
                    ->all(),
            ];
        }

        // ---- the plan --------------------------------------------------
        $entries = [];
        $summary = ['netProfit' => 0, 'temporaryAccounts' => 0, 'permanentAccounts' => 0, 'nextFiscalYearId' => $next?->id];

        if ($settingsReady && $unclassifiedGroups === []) {
            [$entries, $summary] = $this->buildPlan($items, $settings, $year, $next);
        }

        $hasPosted = DB::table('journal_entries')
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', 'posted')
            ->exists();
        if (! $hasPosted) {
            $warnings[] = ['code' => 'no_entries', 'message' => 'This fiscal year has no posted entries.'];
        } elseif ($blockers === [] && $entries === []) {
            $warnings[] = ['code' => 'nothing_to_close', 'message' => 'All balances are already zero: there is nothing to close.'];
        }

        return [
            'fiscalYearId' => $fiscalYearId,
            'status' => $year->status,
            'canGenerate' => $blockers === [] && $entries !== [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'summary' => $summary,
            'entries' => $entries,
        ];
    }

    /**
     * @param array<int, array{account: int, detail: ?int, balance: int, category: string}> $items
     * @param array<string, mixed> $settings
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function buildPlan(array $items, array $settings, object $year, ?object $next): array
    {
        $profitLoss = (int) $settings['profit_loss_account_id'];
        $retained = (int) $settings['retained_earnings_account_id'];
        $closing = (int) $settings['closing_account_id'];
        $opening = (int) $settings['opening_account_id'];

        $accountIds = array_values(array_unique(array_merge(array_column($items, 'account'), [$profitLoss, $retained, $closing, $opening])));
        $accounts = DB::table('accounts')->whereIn('id', $accountIds)->get(['id', 'code', 'name'])->keyBy('id');

        $detailIds = array_values(array_unique(array_filter(array_column($items, 'detail'))));
        $details = $detailIds === [] ? collect() : DB::table('detail_accounts')->whereIn('id', $detailIds)->pluck('name', 'id');

        $byCode = fn (array $a, array $b): int => strcmp($accounts[$a['account']]->code, $accounts[$b['account']]->code)
            ?: (($a['detail'] ?? 0) <=> ($b['detail'] ?? 0));

        $temporary = [];
        $permanent = [];
        foreach ($items as $item) {
            if (in_array($item['category'], self::TEMPORARY, true)) {
                $temporary[] = $item;
            } else {
                $permanent[$this->key($item['account'], $item['detail'])] = $item;
            }
        }

        $make = fn (string $type, int $fiscalYearId, string $date, string $description, array $lines): array => $this->makeEntry(
            $type,
            $fiscalYearId,
            $date,
            $description,
            $lines,
            $accounts,
            $details,
        );

        $entries = [];

        // 1. close the revenue and expense accounts
        usort($temporary, $byCode);
        $temporaryLines = array_map(fn (array $item) => $this->closingLine($item), $temporary);

        if ($temporaryLines !== []) {
            $entries[] = $make(
                'temporary',
                (int) $year->id,
                $year->end_date,
                "سند بستن حساب‌های موقت سال مالی {$year->title}",
                $this->withCounterAccount($temporaryLines, $profitLoss),
            );
        }

        // closing a credit balance is a debit line: profit = revenue - expenses
        $profit = array_sum(array_column($temporaryLines, 'debit')) - array_sum(array_column($temporaryLines, 'credit'));

        // 2. move the profit (or loss) to retained earnings
        if ($profit !== 0) {
            $amount = abs($profit);
            $lines = $profit > 0
                ? [$this->line($profitLoss, null, $amount, 0), $this->line($retained, null, 0, $amount)]
                : [$this->line($profitLoss, null, 0, $amount), $this->line($retained, null, $amount, 0)];

            $entries[] = $make(
                'profit',
                (int) $year->id,
                $year->end_date,
                "سند انتقال سود و زیان سال مالی {$year->title} به سود (زیان) انباشته",
                $lines,
            );

            // retained earnings holds the result before the permanent accounts are closed
            $retainedKey = $this->key($retained, null);
            $permanent[$retainedKey] = [
                'account' => $retained,
                'detail' => null,
                'category' => 'equity',
                'balance' => ($permanent[$retainedKey]['balance'] ?? 0) - $profit,
            ];
        }

        // 3 and 4. close the permanent accounts, and open them in the next year
        $permanent = array_values(array_filter($permanent, fn (array $item) => $item['balance'] !== 0));
        usort($permanent, $byCode);

        if ($permanent !== []) {
            $entries[] = $make(
                'closing',
                (int) $year->id,
                $year->end_date,
                "سند اختتامیه سال مالی {$year->title}",
                $this->withCounterAccount(array_map(fn (array $item) => $this->closingLine($item), $permanent), $closing),
            );

            if ($next !== null) {
                $entries[] = $make(
                    'opening',
                    (int) $next->id,
                    $next->start_date,
                    "سند افتتاحیه سال مالی {$next->title} (از سال مالی {$year->title})",
                    $this->withCounterAccount(array_map(fn (array $item) => $this->openingLine($item), $permanent), $opening),
                );
            }
        }

        $summary = [
            'netProfit' => $profit,
            'temporaryAccounts' => count($temporary),
            'permanentAccounts' => count($permanent),
            'nextFiscalYearId' => $next === null ? null : (int) $next->id,
        ];

        return [$entries, $summary];
    }

    private function key(int $account, ?int $detail): string
    {
        return $account.'|'.($detail ?? 'null');
    }

    /** @return array{accountId: int, detailId: ?int, debit: int, credit: int} */
    private function line(int $account, ?int $detail, int $debit, int $credit): array
    {
        return ['accountId' => $account, 'detailId' => $detail, 'debit' => $debit, 'credit' => $credit];
    }

    /** The line that brings a balance back to zero: the opposite side. */
    private function closingLine(array $item): array
    {
        $balance = $item['balance'];

        return $this->line($item['account'], $item['detail'], $balance < 0 ? -$balance : 0, $balance > 0 ? $balance : 0);
    }

    /** The line that recreates a balance: the same side. */
    private function openingLine(array $item): array
    {
        $balance = $item['balance'];

        return $this->line($item['account'], $item['detail'], $balance > 0 ? $balance : 0, $balance < 0 ? -$balance : 0);
    }

    /**
     * Adds the counter account: it takes the opposite side of everything above, as one debit line
     * and one credit line, so the entry always balances.
     *
     * @param array<int, array<string, mixed>> $lines
     * @return array<int, array<string, mixed>>
     */
    private function withCounterAccount(array $lines, int $counterAccount): array
    {
        $debit = array_sum(array_column($lines, 'debit'));
        $credit = array_sum(array_column($lines, 'credit'));

        if ($credit > 0) {
            $lines[] = $this->line($counterAccount, null, $credit, 0);
        }
        if ($debit > 0) {
            $lines[] = $this->line($counterAccount, null, 0, $debit);
        }

        return $lines;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function makeEntry(string $type, int $fiscalYearId, string $date, string $description, array $lines, Collection $accounts, Collection $details): array
    {
        return [
            'type' => $type,
            'fiscalYearId' => $fiscalYearId,
            'entryDate' => $date,
            'description' => $description,
            'totalDebit' => array_sum(array_column($lines, 'debit')),
            'totalCredit' => array_sum(array_column($lines, 'credit')),
            'lines' => array_map(fn (array $line) => [
                'accountId' => $line['accountId'],
                'accountCode' => $accounts[$line['accountId']]->code,
                'accountName' => $accounts[$line['accountId']]->name,
                'detailId' => $line['detailId'],
                'detailName' => $line['detailId'] === null ? null : ($details[$line['detailId']] ?? null),
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ], $lines),
        ];
    }
}
