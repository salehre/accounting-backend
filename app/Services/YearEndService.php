<?php

namespace App\Services;

use App\Support\Audit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The year-end entries of a fiscal year.
 *
 * Four entries, always handled together:
 *   temporary - closes revenue and expense accounts against the profit & loss account
 *   profit    - moves the profit (or loss) to retained earnings
 *   closing   - closes asset, liability and equity accounts against the closing account
 *   opening   - recreates those balances on the first day of the next fiscal year
 *
 * State of a fiscal year: none (nothing generated) | drafts (generated, not posted) | posted.
 * Balances come from the POSTED entries of the fiscal year, per (account, detail account) pair,
 * so accounts that require a detail account stay valid.
 */
final class YearEndService
{
    /** The four entries, in the order they are generated and posted. */
    private const TYPES = ['temporary', 'profit', 'closing', 'opening'];

    private const TEMPORARY = ['revenue', 'expense'];

    /** Rows per INSERT: PostgreSQL accepts at most 65,535 parameters in one statement. */
    private const INSERT_CHUNK = 500;

    public function __construct(private readonly JournalEntryService $entries) {}

    // -----------------------------------------------------------------
    // Operations
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    public function preview(int $fiscalYearId): array
    {
        return $this->present($this->analyze($fiscalYearId));
    }

    /** Creates the four draft entries. Returns their ids. */
    public function generate(int $fiscalYearId, int $actorId): array
    {
        return DB::transaction(function () use ($fiscalYearId, $actorId) {
            $this->lockEntries();
            $analysis = $this->analyze($fiscalYearId);

            if ($analysis['state'] !== 'none') {
                abort(409, 'Year-end entries already exist for this fiscal year. Discard the drafts or reverse the posted entries first.');
            }
            if ($analysis['blockers'] !== []) {
                $this->blocked('Year-end entries cannot be generated yet.', $analysis['blockers']);
            }
            if ($analysis['entries'] === []) {
                abort(422, 'There is nothing to close in this fiscal year.');
            }

            $ids = [];
            foreach ($analysis['entries'] as $entry) {
                $entryId = (int) DB::table('journal_entries')->insertGetId([
                    'fiscal_year_id' => $entry['fiscalYearId'],
                    'entry_date' => $entry['entryDate'],
                    'description' => $entry['description'],
                    'source_type' => 'year_end_'.$entry['type'],
                    'source_id' => $fiscalYearId,
                    'created_by' => $actorId,
                ]);

                $rows = [];
                foreach (array_values($entry['lines']) as $index => $line) {
                    $rows[] = [
                        'entry_id' => $entryId,
                        'line_no' => $index + 1,
                        'account_id' => $line['accountId'],
                        'detail_id' => $line['detailId'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                    ];
                }
                foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                    DB::table('journal_lines')->insert($chunk);
                }

                $ids[] = $entryId;
            }

            Audit::log($actorId, 'year_end_generate', 'fiscal_year', $fiscalYearId, [
                'entries' => $ids,
                'netProfit' => $analysis['summary']['netProfit'],
            ]);

            return $ids;
        });
    }

    /** Deletes the draft year-end entries. */
    public function discard(int $fiscalYearId, int $actorId): void
    {
        DB::transaction(function () use ($fiscalYearId, $actorId) {
            $this->lockEntries();

            $existing = $this->activeEntries($fiscalYearId);
            if ($existing === []) {
                abort(409, 'There are no year-end drafts for this fiscal year.');
            }
            if (in_array('posted', array_column($existing, 'status'), true)) {
                abort(409, 'The year-end entries are already posted. Reverse them instead.');
            }

            foreach ($existing as $entry) {
                $this->entries->deleteDraft($entry['id'], $actorId);
            }

            Audit::log($actorId, 'year_end_discard', 'fiscal_year', $fiscalYearId);
        });
    }

    /** Posts the four entries together, after checking they still match the ledger. */
    public function post(int $fiscalYearId, int $actorId): array
    {
        return DB::transaction(function () use ($fiscalYearId, $actorId) {
            // No other entry can be saved or posted while we compare and post
            $this->lockEntries();
            $analysis = $this->analyze($fiscalYearId);

            if ($analysis['state'] === 'none') {
                abort(409, 'Generate the year-end entries first.');
            }
            if ($analysis['state'] === 'posted') {
                abort(409, 'The year-end entries are already posted.');
            }
            if ($analysis['blockers'] !== []) {
                $this->blocked('The year-end entries cannot be posted yet.', $analysis['blockers']);
            }

            foreach ($analysis['existing'] as $entry) {
                $this->entries->post($entry['id'], $actorId);
            }

            Audit::log($actorId, 'year_end_post', 'fiscal_year', $fiscalYearId, [
                'entries' => array_column($analysis['existing'], 'id'),
                'netProfit' => $analysis['summary']['netProfit'],
            ]);

            return $this->preview($fiscalYearId);
        });
    }

    /** Reverses the posted year-end entries, with a written reason. */
    public function reverse(int $fiscalYearId, string $reason, int $actorId): array
    {
        return DB::transaction(function () use ($fiscalYearId, $reason, $actorId) {
            $this->lockEntries();

            $existing = $this->activeEntries($fiscalYearId);
            if ($existing === []) {
                abort(409, 'There are no year-end entries to reverse.');
            }
            if (in_array('draft', array_column($existing, 'status'), true)) {
                abort(409, 'The year-end entries are still drafts. Discard them instead.');
            }

            // Every fiscal year involved (this one and the next) must be open
            $closed = DB::table('fiscal_years')
                ->whereIn('id', array_unique(array_column($existing, 'fiscalYearId')))
                ->where('status', 'closed')
                ->orderBy('start_date')
                ->first(['title']);
            if ($closed) {
                abort(422, "Reopen fiscal year \"{$closed->title}\" first.");
            }

            foreach (array_reverse($existing) as $entry) {
                $reversalId = $this->entries->reverse($entry['id'], $actorId, null);
                $this->entries->post($reversalId, $actorId);
            }

            Audit::log($actorId, 'year_end_reverse', 'fiscal_year', $fiscalYearId, ['reason' => $reason]);

            return $this->preview($fiscalYearId);
        });
    }

    // -----------------------------------------------------------------
    // Analysis
    // -----------------------------------------------------------------

    /**
     * Everything we know about the year-end of a fiscal year. Saves nothing.
     *
     * @return array<string, mixed>
     */
    private function analyze(int $fiscalYearId): array
    {
        $year = DB::table('fiscal_years')->where('id', $fiscalYearId)->first();
        if (! $year) {
            abort(404, 'Fiscal year not found');
        }

        $existing = $this->activeEntries($fiscalYearId);
        $state = 'none';
        if ($existing !== []) {
            $state = in_array('posted', array_column($existing, 'status'), true) ? 'posted' : 'drafts';
        }

        // Posted: the balances are already closed, there is nothing left to plan.
        if ($state === 'posted') {
            $warnings = [];
            if ($year->status === 'closed') {
                $warnings[] = [
                    'code' => 'reopen_to_reverse',
                    'message' => 'This fiscal year is closed. Reopen it before reversing the year-end entries.',
                ];
            }

            return [
                'year' => $year,
                'state' => $state,
                'existing' => $existing,
                'blockers' => [],
                'warnings' => $warnings,
                'summary' => null,
                'entries' => [],
            ];
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

        // Drafts of the year-end entries themselves do not count
        $draftCount = DB::table('journal_entries')
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', 'draft')
            ->whereNotIn('source_type', $this->sourceTypes())
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

        // The technical accounts must end with a zero balance: they only balance the year-end entries.
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

        if ($state === 'none') {
            $hasPosted = DB::table('journal_entries')
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('status', 'posted')
                ->exists();

            if (! $hasPosted) {
                $warnings[] = ['code' => 'no_entries', 'message' => 'This fiscal year has no posted entries.'];
            } elseif ($blockers === [] && $entries === []) {
                $warnings[] = ['code' => 'nothing_to_close', 'message' => 'All balances are already zero: there is nothing to close.'];
            }
        }

        // ---- drafts that no longer match the ledger ---------------------
        if ($state === 'drafts' && $this->differsFromPlan($existing, $entries)) {
            $blockers[] = [
                'code' => 'year_end_stale',
                'message' => 'The ledger or the settings changed after these entries were generated. Discard them and generate them again.',
            ];
        }

        return [
            'year' => $year,
            'state' => $state,
            'existing' => $existing,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'summary' => $summary,
            'entries' => $entries,
        ];
    }

    /** @return array<string, mixed> */
    private function present(array $analysis): array
    {
        $blockers = $analysis['blockers'];
        $state = $analysis['state'];

        $actions = [
            'generate' => $state === 'none' && $blockers === [] && $analysis['entries'] !== [],
            'discard' => $state === 'drafts',
            'post' => $state === 'drafts' && $blockers === [],
            'reverse' => $state === 'posted',
        ];

        return [
            'fiscalYearId' => (int) $analysis['year']->id,
            'status' => $analysis['year']->status,
            'state' => $state,
            'canGenerate' => $actions['generate'],
            'actions' => $actions,
            'blockers' => $blockers,
            'warnings' => $analysis['warnings'],
            'summary' => $analysis['summary'],
            'entries' => $analysis['entries'],
            'existing' => $analysis['existing'],
        ];
    }

    // -----------------------------------------------------------------
    // The plan
    // -----------------------------------------------------------------

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

    /**
     * True when the saved draft entries are not exactly what the ledger asks for today.
     *
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $plan
     */
    private function differsFromPlan(array $existing, array $plan): bool
    {
        $storedLines = DB::table('journal_lines')
            ->whereIn('entry_id', array_column($existing, 'id'))
            ->get(['entry_id', 'account_id', 'detail_id', 'debit', 'credit'])
            ->groupBy('entry_id');

        $stored = [];
        foreach ($existing as $entry) {
            $lines = $storedLines->get($entry['id'], collect())->map(fn ($line) => [
                'accountId' => (int) $line->account_id,
                'detailId' => $line->detail_id === null ? null : (int) $line->detail_id,
                'debit' => (int) $line->debit,
                'credit' => (int) $line->credit,
            ])->all();

            $stored[$entry['type']] = [
                'fiscalYearId' => $entry['fiscalYearId'],
                'entryDate' => $entry['entryDate'],
                'lines' => $this->canonical($lines),
            ];
        }

        $planned = [];
        foreach ($plan as $entry) {
            $planned[$entry['type']] = [
                'fiscalYearId' => $entry['fiscalYearId'],
                'entryDate' => $entry['entryDate'],
                'lines' => $this->canonical(array_map(fn (array $line) => [
                    'accountId' => $line['accountId'],
                    'detailId' => $line['detailId'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ], $entry['lines'])),
            ];
        }

        ksort($stored);
        ksort($planned);

        return $stored !== $planned;
    }

    /**
     * @param array<int, array{accountId: int, detailId: ?int, debit: int, credit: int}> $lines
     * @return array<int, array{0: int, 1: ?int, 2: int, 3: int}>
     */
    private function canonical(array $lines): array
    {
        usort($lines, fn (array $a, array $b) => [$a['accountId'], $a['detailId'] ?? 0, $a['debit'], $a['credit']]
            <=> [$b['accountId'], $b['detailId'] ?? 0, $b['debit'], $b['credit']]);

        return array_map(fn (array $line) => [$line['accountId'], $line['detailId'], $line['debit'], $line['credit']], $lines);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The year-end entries of a fiscal year that have not been reversed, in generation order.
     *
     * @return array<int, array{id: int, type: string, status: string, number: ?int, fiscalYearId: int, entryDate: string}>
     */
    private function activeEntries(int $fiscalYearId): array
    {
        $existing = DB::table('journal_entries as e')
            ->whereIn('e.source_type', $this->sourceTypes())
            ->where('e.source_id', $fiscalYearId)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'e.id');
            })
            ->get(['e.id', 'e.source_type', 'e.status', 'e.number', 'e.fiscal_year_id', 'e.entry_date'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'type' => substr($row->source_type, strlen('year_end_')),
                'status' => $row->status,
                'number' => $row->number === null ? null : (int) $row->number,
                'fiscalYearId' => (int) $row->fiscal_year_id,
                'entryDate' => $row->entry_date,
            ])
            ->all();

        usort($existing, fn (array $a, array $b) => array_search($a['type'], self::TYPES, true) <=> array_search($b['type'], self::TYPES, true));

        return $existing;
    }

    /** @return array<int, string> */
    private function sourceTypes(): array
    {
        return array_map(fn (string $type) => 'year_end_'.$type, self::TYPES);
    }

    /**
     * Serialises year-end work with every other change of entries: nothing can be saved or
     * posted while the year-end entries are being compared and posted.
     */
    private function lockEntries(): void
    {
        DB::statement('LOCK TABLE journal_entries IN SHARE ROW EXCLUSIVE MODE');
    }

    /** @param array<int, array<string, mixed>> $blockers */
    private function blocked(string $message, array $blockers): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'blockers' => $blockers,
        ], 422));
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
