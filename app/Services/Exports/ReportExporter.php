<?php

namespace App\Services\Exports;

use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\IncomeStatementReport;
use App\Services\Reports\JournalReport;
use App\Services\Reports\LedgerReport;
use App\Services\Reports\TrialBalanceReport;
use App\Support\DateFormat;
use Illuminate\Support\Facades\DB;

/**
 * Turns the reports into .xlsx files. Every method returns the path of a temporary file.
 * The big reports (ledger, journal) are read in chunks, so memory stays small.
 *
 * Optional settings (config): accounting.export_chunk (rows per read, default 2000)
 * and accounting.export_max_rows (default 500000).
 */
final class ReportExporter
{
    public const FISCAL_YEAR = 'سال مالی:';

    public const RANGE = 'بازه:';

    public const DATE = 'تاریخ:';

    public const TO = 'تا';

    public const ACCOUNT = 'حساب:';

    public const WARNING_PREFIX = 'هشدار:';

    public const TITLE_TRIAL_BALANCE = 'تراز آزمایشی';

    public const TITLE_LEDGER = 'دفتر معین';

    public const TITLE_JOURNAL = 'دفتر روزنامه';

    public const TITLE_INCOME = 'صورت سود و زیان';

    public const TITLE_BALANCE_SHEET = 'ترازنامه';

    public const COLUMN_CODE = 'کد حساب';

    public const COLUMN_DETAIL_CODE = 'کد تفصیلی';

    public const COLUMN_DATE = 'تاریخ';

    public const COLUMN_ENTRY_NUMBER = 'شماره سند';

    public const TOTAL = 'جمع';

    public const BALANCED = 'تراز است';

    public const NOT_BALANCED = 'تراز نیست';

    public const OPENING_BALANCE = 'مانده ابتدای دوره';

    public const PERIOD_TOTAL = 'جمع گردش';

    public const CLOSING_BALANCE = 'مانده پایان';

    public const SECTION_REVENUE = 'درآمدها';

    public const SECTION_EXPENSES = 'هزینه‌ها';

    public const TOTAL_REVENUE = 'جمع درآمدها';

    public const TOTAL_EXPENSES = 'جمع هزینه‌ها';

    public const NET_PROFIT = 'سود (زیان) خالص';

    public const SECTION_ASSETS = 'دارایی‌ها';

    public const SECTION_LIABILITIES = 'بدهی‌ها';

    public const SECTION_EQUITY = 'حقوق صاحبان سرمایه';

    public const TOTAL_ASSETS = 'جمع دارایی‌ها';

    public const TOTAL_LIABILITIES = 'جمع بدهی‌ها';

    public const TOTAL_EQUITY = 'جمع حقوق صاحبان سرمایه';

    public const CURRENT_RESULT = 'سود (زیان) دوره جاری';

    public const TOTAL_LIABILITIES_EQUITY = 'جمع بدهی‌ها و حقوق صاحبان سرمایه';

    public const DIFFERENCE = 'اختلاف';

    /** What the "type" column of the journal book says. */
    public const SOURCE_TYPES = [
        'manual' => 'عادی',
        'reversal' => 'برگشت',
        'year_end_temporary' => 'بستن حساب‌های موقت',
        'year_end_profit' => 'انتقال سود و زیان',
        'year_end_closing' => 'اختتامیه',
        'year_end_opening' => 'افتتاحیه',
    ];

    public function __construct(
        private readonly TrialBalanceReport $trialBalance,
        private readonly LedgerReport $ledger,
        private readonly JournalReport $journal,
        private readonly IncomeStatementReport $income,
        private readonly BalanceSheetReport $balanceSheet,
    ) {}

    // -----------------------------------------------------------------
    // Trial balance
    // -----------------------------------------------------------------

    public function trialBalance(
        int $fiscalYearId,
        ?string $from,
        ?string $to,
        int $level,
        bool $withDetails,
        ?int $accountId,
        bool $includeClosing,
        bool $hideZero,
        string $calendar,
    ): string {
        $report = $this->trialBalance->generate($fiscalYearId, $from, $to, $level, $withDetails, $accountId, $includeClosing, $hideZero);

        $labels = [self::COLUMN_CODE, 'نام حساب'];
        $widths = [14, 36];
        if ($withDetails) {
            array_push($labels, self::COLUMN_DETAIL_CODE, 'نام تفصیلی');
            array_push($widths, 14, 28);
        }

        $firstNumber = count($labels);
        array_push($labels, 'مانده ابتدای دوره (بدهکار)', 'مانده ابتدای دوره (بستانکار)', 'گردش بدهکار', 'گردش بستانکار', 'مانده پایان (بدهکار)', 'مانده پایان (بستانکار)');
        array_push($widths, 22, 22, 18, 18, 22, 22);

        $sheet = new SpreadsheetBuilder(self::TITLE_TRIAL_BALANCE, $widths, range($firstNumber, $firstNumber + 5));
        $sheet->title(self::TITLE_TRIAL_BALANCE);
        $this->intro($sheet, $report['fiscalYear']['title'], self::RANGE.' '.$this->range($report['from'], $report['to'], $calendar));
        $sheet->header($labels);

        foreach ($report['rows'] as $row) {
            $values = [$row['code'], $row['name']];
            if ($withDetails) {
                $values[] = $row['detailCode'] ?? '';
                $values[] = $row['detailName'] ?? '';
            }
            array_push($values, $row['openingDebit'], $row['openingCredit'], $row['periodDebit'], $row['periodCredit'], $row['closingDebit'], $row['closingCredit']);
            $sheet->row($values);
        }

        $t = $report['totals'];
        $totals = [self::TOTAL, ''];
        if ($withDetails) {
            array_push($totals, '', '');
        }
        array_push($totals, $t['openingDebit'], $t['openingCredit'], $t['periodDebit'], $t['periodCredit'], $t['closingDebit'], $t['closingCredit']);
        $sheet->total($totals);

        if ($report['balanced'] !== null) {
            $sheet->line($report['balanced'] ? self::BALANCED : self::NOT_BALANCED);
        }

        return $sheet->finish();
    }

    // -----------------------------------------------------------------
    // Ledger (read in chunks)
    // -----------------------------------------------------------------

    public function ledger(
        int $fiscalYearId,
        int $accountId,
        ?int $detailId,
        ?string $from,
        ?string $to,
        bool $includeClosing,
        string $calendar,
    ): string {
        $chunk = $this->chunk();
        $max = $this->maxRows();

        $page = $this->ledger->generate($fiscalYearId, $accountId, $detailId, $from, $to, $includeClosing, null, $chunk);
        $totals = $page['totals'];

        $account = $page['account']['code'].' - '.$page['account']['name'];
        if ($detailId !== null) {
            $detail = DB::table('detail_accounts')->where('id', $detailId)->first(['code', 'name']);
            if ($detail) {
                $account .= '  |  تفصیلی: '.$detail->code.' - '.$detail->name;
            }
        }

        $sheet = new SpreadsheetBuilder(self::TITLE_LEDGER, [14, 12, 34, 12, 28, 24, 28, 18, 18, 20], [7, 8, 9]);
        $sheet->title(self::TITLE_LEDGER);
        $this->intro(
            $sheet,
            $page['fiscalYear']['title'],
            self::RANGE.' '.$this->range($page['from'], $page['to'], $calendar),
            self::ACCOUNT.' '.$account,
        );
        $sheet->header([self::COLUMN_DATE, self::COLUMN_ENTRY_NUMBER, 'شرح سند', self::COLUMN_CODE, 'نام حساب', 'تفصیلی', 'شرح ردیف', 'بدهکار', 'بستانکار', 'مانده']);
        $sheet->row(['', '', self::OPENING_BALANCE, '', '', '', '', '', '', $page['openingBalance']]);

        $count = 0;
        while (true) {
            foreach ($page['items'] as $item) {
                if (++$count > $max) {
                    $this->tooLarge($sheet, $max);
                }

                $sheet->row([
                    DateFormat::show($item['date'], $calendar),
                    $item['number'],
                    $item['entryDescription'] ?? '',
                    $item['accountCode'],
                    $item['accountName'],
                    $item['detailName'] ?? '',
                    $item['lineDescription'] ?? '',
                    $item['debit'],
                    $item['credit'],
                    $item['balance'],
                ]);
            }

            if ($page['nextCursor'] === null) {
                break;
            }
            $page = $this->ledger->generate($fiscalYearId, $accountId, $detailId, $from, $to, $includeClosing, $page['nextCursor'], $chunk);
        }

        $sheet->total(['', '', self::PERIOD_TOTAL, '', '', '', '', $totals['periodDebit'], $totals['periodCredit'], '']);
        $sheet->total(['', '', self::CLOSING_BALANCE, '', '', '', '', '', '', $totals['closingBalance']]);

        return $sheet->finish();
    }

    // -----------------------------------------------------------------
    // Journal book (read in chunks, one row per line)
    // -----------------------------------------------------------------

    public function journal(
        int $fiscalYearId,
        ?string $from,
        ?string $to,
        ?int $fromNumber,
        ?int $toNumber,
        string $calendar,
    ): string {
        $chunk = $this->chunk();
        $max = $this->maxRows();

        $page = $this->journal->generate($fiscalYearId, $from, $to, $fromNumber, $toNumber, null, $chunk);
        $totals = $page['totals'];

        $range = self::RANGE.' '.$this->range($page['from'], $page['to'], $calendar);
        if ($fromNumber !== null || $toNumber !== null) {
            $range .= '  |  '.self::COLUMN_ENTRY_NUMBER.': '.($fromNumber ?? '...').' '.self::TO.' '.($toNumber ?? '...');
        }

        $sheet = new SpreadsheetBuilder(self::TITLE_JOURNAL, [12, 14, 34, 22, 12, 28, 24, 28, 18, 18], [8, 9]);
        $sheet->title(self::TITLE_JOURNAL);
        $this->intro($sheet, $page['fiscalYear']['title'], $range);
        $sheet->header([self::COLUMN_ENTRY_NUMBER, self::COLUMN_DATE, 'شرح سند', 'نوع سند', self::COLUMN_CODE, 'نام حساب', 'تفصیلی', 'شرح ردیف', 'بدهکار', 'بستانکار']);

        $count = 0;
        while (true) {
            foreach ($page['items'] as $entry) {
                foreach ($entry['lines'] as $line) {
                    if (++$count > $max) {
                        $this->tooLarge($sheet, $max);
                    }

                    $sheet->row([
                        $entry['number'],
                        DateFormat::show($entry['date'], $calendar),
                        $entry['description'] ?? '',
                        self::SOURCE_TYPES[$entry['sourceType']] ?? $entry['sourceType'],
                        $line['accountCode'],
                        $line['accountName'],
                        $line['detailName'] ?? '',
                        $line['description'] ?? '',
                        $line['debit'],
                        $line['credit'],
                    ]);
                }
            }

            if ($page['nextCursor'] === null) {
                break;
            }
            $page = $this->journal->generate($fiscalYearId, $from, $to, $fromNumber, $toNumber, $page['nextCursor'], $chunk);
        }

        $sheet->total([self::TOTAL, '', '', '', '', '', '', '', $totals['debit'], $totals['credit']]);

        return $sheet->finish();
    }

    // -----------------------------------------------------------------
    // Income statement and balance sheet
    // -----------------------------------------------------------------

    public function incomeStatement(int $fiscalYearId, ?string $from, ?string $to, int $level, string $calendar): string
    {
        $report = $this->income->generate($fiscalYearId, $from, $to, $level);

        $sheet = new SpreadsheetBuilder(self::TITLE_INCOME, [14, 44, 22], [2]);
        $sheet->title(self::TITLE_INCOME);
        $this->intro($sheet, $report['fiscalYear']['title'], self::RANGE.' '.$this->range($report['from'], $report['to'], $calendar));
        $sheet->header([self::COLUMN_CODE, 'نام حساب', 'مبلغ']);

        $sheet->section(self::SECTION_REVENUE);
        foreach ($report['revenue']['rows'] as $row) {
            $sheet->row([$row['code'], $row['name'], $row['amount']]);
        }
        $sheet->total([self::TOTAL_REVENUE, '', $report['revenue']['total']]);

        $sheet->section(self::SECTION_EXPENSES);
        foreach ($report['expenses']['rows'] as $row) {
            $sheet->row([$row['code'], $row['name'], $row['amount']]);
        }
        $sheet->total([self::TOTAL_EXPENSES, '', $report['expenses']['total']]);

        $sheet->total([self::NET_PROFIT, '', $report['netProfit']]);
        $this->warnings($sheet, $report);

        return $sheet->finish();
    }

    public function balanceSheet(int $fiscalYearId, ?string $date, int $level, string $calendar): string
    {
        $report = $this->balanceSheet->generate($fiscalYearId, $date, $level);

        $sheet = new SpreadsheetBuilder(self::TITLE_BALANCE_SHEET, [14, 44, 22], [2]);
        $sheet->title(self::TITLE_BALANCE_SHEET);
        $this->intro($sheet, $report['fiscalYear']['title'], self::DATE.' '.DateFormat::show($report['date'], $calendar));
        $sheet->header([self::COLUMN_CODE, 'نام حساب', 'مبلغ']);

        foreach ([
                     [self::SECTION_ASSETS, 'assets', self::TOTAL_ASSETS],
                     [self::SECTION_LIABILITIES, 'liabilities', self::TOTAL_LIABILITIES],
                     [self::SECTION_EQUITY, 'equity', self::TOTAL_EQUITY],
                 ] as [$section, $key, $totalLabel]) {
            $sheet->section($section);
            foreach ($report[$key]['rows'] as $row) {
                $sheet->row([$row['code'], $row['name'], $row['amount']]);
            }
            $sheet->total([$totalLabel, '', $report[$key]['total']]);
        }

        $sheet->total([self::CURRENT_RESULT, '', $report['currentPeriodResult']]);
        $sheet->total([self::TOTAL_LIABILITIES_EQUITY, '', $report['liabilitiesAndEquity']]);
        $sheet->total([self::DIFFERENCE, '', $report['difference']]);
        $sheet->line($report['balanced'] ? self::BALANCED : self::NOT_BALANCED);
        $this->warnings($sheet, $report);

        return $sheet->finish();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function intro(SpreadsheetBuilder $sheet, string $yearTitle, string $secondLine, ?string $extra = null): void
    {
        $sheet->line(self::FISCAL_YEAR.' '.$yearTitle.($extra === null ? '' : '  |  '.$extra));
        $sheet->line($secondLine);
        $sheet->blank();
    }

    private function range(string $from, string $to, string $calendar): string
    {
        return DateFormat::show($from, $calendar).' '.self::TO.' '.DateFormat::show($to, $calendar);
    }

    /** @param array<string, mixed> $report */
    private function warnings(SpreadsheetBuilder $sheet, array $report): void
    {
        if ($report['complete']) {
            return;
        }

        $sheet->blank();
        $sheet->line(self::WARNING_PREFIX.' حساب‌هایی در گروه‌هایی هستند که نوع ندارند و در گزارش نیامده‌اند؛ گزارش ناقص است. حساب‌ها:');

        foreach ($report['warnings'][0]['accounts'] ?? [] as $account) {
            $sheet->row([$account['code'], $account['name'], $account['balance']]);
        }
    }

    private function chunk(): int
    {
        return max(1, (int) config('accounting.export_chunk', 2000));
    }

    private function maxRows(): int
    {
        return max(1, (int) config('accounting.export_max_rows', 500000));
    }

    private function tooLarge(SpreadsheetBuilder $sheet, int $max): never
    {
        $sheet->discard();

        abort(422, "The export has more than {$max} rows. Narrow it down by date or account.");
    }
}
