<?php

namespace App\Http\Controllers;

use App\Services\Exports\ReportExporter;
use App\Support\Audit;
use App\Support\DateFormat;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private readonly ReportExporter $exporter) {}

    public function trialBalance(Request $request): BinaryFileResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'level' => ['nullable', 'integer', 'in:1,2,3'],
            'withDetails' => ['nullable', 'boolean'],
            'accountId' => ['nullable', 'integer', 'exists:accounts,id'],
            'includeClosing' => ['nullable', 'boolean'],
            'hideZero' => ['nullable', 'boolean'],
            'calendar' => ['nullable', Rule::in(DateFormat::CALENDARS)],
        ]);

        $path = $this->exporter->trialBalance(
            (int) $query['fiscalYearId'],
            $query['from'] ?? null,
            $query['to'] ?? null,
            (int) ($query['level'] ?? 3),
            $request->boolean('withDetails'),
            isset($query['accountId']) ? (int) $query['accountId'] : null,
            $request->boolean('includeClosing'),
            $request->boolean('hideZero', true),
            $query['calendar'] ?? 'jalali',
        );

        return $this->download($request, 'trial-balance', $query, $path);
    }

    public function ledger(Request $request): BinaryFileResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'accountId' => ['required', 'integer', 'exists:accounts,id'],
            'detailId' => ['nullable', 'integer', 'exists:detail_accounts,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'includeClosing' => ['nullable', 'boolean'],
            'calendar' => ['nullable', Rule::in(DateFormat::CALENDARS)],
        ]);

        $path = $this->exporter->ledger(
            (int) $query['fiscalYearId'],
            (int) $query['accountId'],
            isset($query['detailId']) ? (int) $query['detailId'] : null,
            $query['from'] ?? null,
            $query['to'] ?? null,
            $request->boolean('includeClosing'),
            $query['calendar'] ?? 'jalali',
        );

        return $this->download($request, 'ledger', $query, $path);
    }

    public function journal(Request $request): BinaryFileResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'fromNumber' => ['nullable', 'integer', 'min:1'],
            'toNumber' => ['nullable', 'integer', 'min:1'],
            'calendar' => ['nullable', Rule::in(DateFormat::CALENDARS)],
        ]);

        $path = $this->exporter->journal(
            (int) $query['fiscalYearId'],
            $query['from'] ?? null,
            $query['to'] ?? null,
            isset($query['fromNumber']) ? (int) $query['fromNumber'] : null,
            isset($query['toNumber']) ? (int) $query['toNumber'] : null,
            $query['calendar'] ?? 'jalali',
        );

        return $this->download($request, 'journal', $query, $path);
    }

    public function incomeStatement(Request $request): BinaryFileResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'level' => ['nullable', 'integer', 'in:1,2,3'],
            'calendar' => ['nullable', Rule::in(DateFormat::CALENDARS)],
        ]);

        $path = $this->exporter->incomeStatement(
            (int) $query['fiscalYearId'],
            $query['from'] ?? null,
            $query['to'] ?? null,
            (int) ($query['level'] ?? 2),
            $query['calendar'] ?? 'jalali',
        );

        return $this->download($request, 'income-statement', $query, $path);
    }

    public function balanceSheet(Request $request): BinaryFileResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'level' => ['nullable', 'integer', 'in:1,2,3'],
            'calendar' => ['nullable', Rule::in(DateFormat::CALENDARS)],
        ]);

        $path = $this->exporter->balanceSheet(
            (int) $query['fiscalYearId'],
            $query['date'] ?? null,
            (int) ($query['level'] ?? 2),
            $query['calendar'] ?? 'jalali',
        );

        return $this->download($request, 'balance-sheet', $query, $path);
    }

    /**
     * Sends the file, records who exported what, and deletes the temporary file afterwards.
     *
     * @param array<string, mixed> $query
     */
    private function download(Request $request, string $report, array $query, string $path): BinaryFileResponse
    {
        Audit::log($request->user()->id, 'export', 'report', null, ['report' => $report, 'query' => $query]);

        $name = $report.'-fy'.$query['fiscalYearId'].'-'.now()->format('Y-m-d').'.xlsx';

        return response()
            ->download($path, $name, ['Content-Type' => self::XLSX])
            ->deleteFileAfterSend(true);
    }
}
