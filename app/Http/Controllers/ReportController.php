<?php

namespace App\Http\Controllers;

use App\Services\Reports\BalanceSheetReport;
use App\Services\Reports\IncomeStatementReport;
use App\Services\Reports\JournalReport;
use App\Services\Reports\LedgerReport;
use App\Services\Reports\TrialBalanceReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function trialBalance(Request $request, TrialBalanceReport $report): JsonResponse
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
        ]);

        return response()->json($report->generate(
            (int) $query['fiscalYearId'],
            $query['from'] ?? null,
            $query['to'] ?? null,
            (int) ($query['level'] ?? 3),
            $request->boolean('withDetails'),
            isset($query['accountId']) ? (int) $query['accountId'] : null,
            $request->boolean('includeClosing'),
            $request->boolean('hideZero', true),
        ));
    }

    public function ledger(Request $request, LedgerReport $report): JsonResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'accountId' => ['required', 'integer', 'exists:accounts,id'],
            'detailId' => ['nullable', 'integer', 'exists:detail_accounts,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'includeClosing' => ['nullable', 'boolean'],
            'cursor' => ['nullable', 'string', 'max:500'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($report->generate(
            (int) $query['fiscalYearId'],
            (int) $query['accountId'],
            isset($query['detailId']) ? (int) $query['detailId'] : null,
            $query['from'] ?? null,
            $query['to'] ?? null,
            $request->boolean('includeClosing'),
            $query['cursor'] ?? null,
            (int) ($query['pageSize'] ?? 50),
        ));
    }

    public function incomeStatement(Request $request, IncomeStatementReport $report): JsonResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'level' => ['nullable', 'integer', 'in:1,2,3'],
        ]);

        return response()->json($report->generate(
            (int) $query['fiscalYearId'],
            $query['from'] ?? null,
            $query['to'] ?? null,
            (int) ($query['level'] ?? 2),
        ));
    }

    public function balanceSheet(Request $request, BalanceSheetReport $report): JsonResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'level' => ['nullable', 'integer', 'in:1,2,3'],
        ]);

        return response()->json($report->generate(
            (int) $query['fiscalYearId'],
            $query['date'] ?? null,
            (int) ($query['level'] ?? 2),
        ));
    }

    public function journal(Request $request, JournalReport $report): JsonResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['required', 'integer', 'exists:fiscal_years,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'fromNumber' => ['nullable', 'integer', 'min:1'],
            'toNumber' => ['nullable', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:500'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($report->generate(
            (int) $query['fiscalYearId'],
            $query['from'] ?? null,
            $query['to'] ?? null,
            isset($query['fromNumber']) ? (int) $query['fromNumber'] : null,
            isset($query['toNumber']) ? (int) $query['toNumber'] : null,
            $query['cursor'] ?? null,
            (int) ($query['pageSize'] ?? 20),
        ));
    }
}
