<?php

namespace App\Services\Reports;

use App\Support\CategoryBalances;
use App\Support\ReportScope;

/**
 * Income statement of a period: revenue minus expenses, per account (level 1, 2 or 3).
 * The year-end closing entries are never included.
 */
final class IncomeStatementReport
{
    /** @return array<string, mixed> */
    public function generate(int $fiscalYearId, ?string $from, ?string $to, int $level): array
    {
        $scope = ReportScope::resolve($fiscalYearId, $from, $to);

        $revenue = [];
        $expenses = [];
        $unclassified = [];

        foreach (CategoryBalances::between($fiscalYearId, $scope['from'], $scope['to'], $level) as $row) {
            $item = ['accountId' => $row['accountId'], 'code' => $row['code'], 'name' => $row['name'], 'level' => $level];

            if ($row['category'] === 'revenue') {
                $revenue[] = $item + ['amount' => -$row['net']]; // credit balance = revenue
            } elseif ($row['category'] === 'expense') {
                $expenses[] = $item + ['amount' => $row['net']];
            } elseif ($row['category'] === null) {
                $unclassified[] = ['accountId' => $row['accountId'], 'code' => $row['code'], 'name' => $row['name'], 'balance' => $row['net']];
            }
            // assets, liabilities and equity are not part of the income statement
        }

        $totalRevenue = array_sum(array_column($revenue, 'amount'));
        $totalExpenses = array_sum(array_column($expenses, 'amount'));

        $warnings = [];
        if ($unclassified !== []) {
            $warnings[] = [
                'code' => 'unclassified_accounts',
                'message' => 'Some accounts belong to groups without a category (revenue, expense...), so the result may be incomplete. Classify the groups first.',
                'accounts' => $unclassified,
            ];
        }

        return [
            'fiscalYear' => ['id' => (int) $scope['year']->id, 'title' => $scope['year']->title],
            'from' => $scope['from'],
            'to' => $scope['to'],
            'level' => $level,
            'revenue' => ['rows' => $revenue, 'total' => $totalRevenue],
            'expenses' => ['rows' => $expenses, 'total' => $totalExpenses],
            'netProfit' => $totalRevenue - $totalExpenses,
            'complete' => $unclassified === [],
            'warnings' => $warnings,
        ];
    }
}
