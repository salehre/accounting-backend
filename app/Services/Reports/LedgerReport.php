<?php

namespace App\Services\Reports;

use App\Support\ReportScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ledger of an account (a movement account, or a kol or group with everything below it),
 * in date order with a running balance. Pages are fetched with a signed cursor (keyset paging),
 * so the 5,000th page is as fast as the first.
 */
final class LedgerReport
{
    /** @return array<string, mixed> */
    public function generate(
        int $fiscalYearId,
        int $accountId,
        ?int $detailId,
        ?string $from,
        ?string $to,
        bool $includeClosing,
        ?string $cursor,
        int $pageSize,
    ): array {
        $scope = ReportScope::resolve($fiscalYearId, $from, $to);
        $from = $scope['from'];
        $to = $scope['to'];

        $account = DB::table('accounts')->where('id', $accountId)->first(['id', 'code', 'name', 'level']);
        if (! $account) {
            abort(404, 'Account not found');
        }

        // The movement accounts whose lines make up this ledger
        $accountIds = DB::table('accounts as m')
            ->leftJoin('accounts as k', 'k.id', '=', 'm.parent_id')
            ->where('m.level', 3)
            ->where(function ($query) use ($accountId) {
                $query->where('m.id', $accountId)
                    ->orWhere('m.parent_id', $accountId)
                    ->orWhere('k.parent_id', $accountId);
            })
            ->pluck('m.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $header = [
            'fiscalYear' => ['id' => (int) $scope['year']->id, 'title' => $scope['year']->title],
            'account' => ['id' => (int) $account->id, 'code' => $account->code, 'name' => $account->name, 'level' => (int) $account->level],
            'detailId' => $detailId,
            'from' => $from,
            'to' => $to,
            'includeClosing' => $includeClosing,
        ];

        if ($accountIds === []) {
            return $header + [
                    'openingBalance' => 0,
                    'totals' => ['periodDebit' => 0, 'periodCredit' => 0, 'closingBalance' => 0],
                    'items' => [],
                    'nextCursor' => null,
                ];
        }

        // ---- opening balance and totals: from the daily totals ----------
        $totals = fn () => DB::table('account_daily_totals as t')
            ->where('t.fiscal_year_id', $fiscalYearId)
            ->whereIn('t.account_id', $accountIds)
            ->when($detailId !== null, fn ($q) => $q->where('t.detail_id', $detailId))
            ->when(! $includeClosing, fn ($q) => $q->where('t.closing', false))
            ->selectRaw('coalesce(sum(t.debit), 0) as debit, coalesce(sum(t.credit), 0) as credit');

        $opening = $totals()->where('t.entry_date', '<', $from)->first();
        $period = $totals()->whereBetween('t.entry_date', [$from, $to])->first();

        $openingBalance = (int) $opening->debit - (int) $opening->credit;
        $periodDebit = (int) $period->debit;
        $periodCredit = (int) $period->credit;

        // ---- one page of lines ------------------------------------------
        $position = $cursor === null ? null : $this->decodeCursor($cursor);

        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('detail_accounts as d', 'd.id', '=', 'l.detail_id')
            ->where('e.status', 'posted')
            ->where('e.fiscal_year_id', $fiscalYearId)
            ->whereBetween('e.entry_date', [$from, $to])
            ->whereIn('l.account_id', $accountIds)
            ->when($detailId !== null, fn ($q) => $q->where('l.detail_id', $detailId))
            ->when(! $includeClosing, fn ($q) => $q->whereRaw(ReportScope::NOT_CLOSING_SQL))
            ->when($position !== null, fn ($q) => $q->whereRaw(
                '(e.entry_date, e.number, l.id) > (?::date, ?::bigint, ?::bigint)',
                [$position['d'], $position['n'], $position['l']],
            ))
            ->orderBy('e.entry_date')
            ->orderBy('e.number')
            ->orderBy('l.id')
            ->limit($pageSize + 1)
            ->selectRaw('l.id as "lineId", e.id as "entryId", e.number, e.entry_date as "date",
                         e.description as "entryDescription", l.description as "lineDescription",
                         a.code as "accountCode", a.name as "accountName",
                         l.detail_id as "detailId", d.name as "detailName", l.debit, l.credit')
            ->get();

        $hasMore = $lines->count() > $pageSize;
        $balance = $position['b'] ?? $openingBalance;

        $items = [];
        $last = null;
        foreach ($lines->take($pageSize) as $line) {
            $balance += (int) $line->debit - (int) $line->credit;
            $last = $line;

            $items[] = [
                'lineId' => (int) $line->lineId,
                'entryId' => (int) $line->entryId,
                'number' => (int) $line->number,
                'date' => $line->date,
                'entryDescription' => $line->entryDescription,
                'lineDescription' => $line->lineDescription,
                'accountCode' => $line->accountCode,
                'accountName' => $line->accountName,
                'detailId' => $line->detailId === null ? null : (int) $line->detailId,
                'detailName' => $line->detailName,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
                'balance' => $balance,
            ];
        }

        return $header + [
                'openingBalance' => $openingBalance,
                'totals' => [
                    'periodDebit' => $periodDebit,
                    'periodCredit' => $periodCredit,
                    'closingBalance' => $openingBalance + $periodDebit - $periodCredit,
                ],
                'items' => $items,
                'nextCursor' => $hasMore && $last !== null
                    ? $this->encodeCursor(['d' => $last->date, 'n' => (int) $last->number, 'l' => (int) $last->lineId, 'b' => $balance])
                    : null,
            ];
    }

    /** @param array{d: string, n: int, l: int, b: int} $position */
    private function encodeCursor(array $position): string
    {
        $json = (string) json_encode($position);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'.$this->sign($json);
    }

    /** @return array{d: string, n: int, l: int, b: int} */
    private function decodeCursor(string $cursor): array
    {
        $invalid = fn () => ValidationException::withMessages(['cursor' => 'The cursor is not valid.']);

        $parts = explode('.', $cursor);
        if (count($parts) !== 2) {
            throw $invalid();
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false || ! hash_equals($this->sign($json), $parts[1])) {
            throw $invalid();
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! isset($data['d'], $data['n'], $data['l'], $data['b'])
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['d'])) {
            throw $invalid();
        }

        return ['d' => (string) $data['d'], 'n' => (int) $data['n'], 'l' => (int) $data['l'], 'b' => (int) $data['b']];
    }

    /** The cursor carries the running balance, so it is signed: nobody can alter it. */
    private function sign(string $json): string
    {
        return hash_hmac('sha256', $json, (string) config('app.key'));
    }
}
