<?php

namespace App\Services\Reports;

use App\Support\CategoryBalances;
use App\Support\ReportScope;
use Illuminate\Validation\ValidationException;

/**
 * Balance sheet as of a date: assets, liabilities and equity, and the result of the period so far
 * (the year-end closing entries are never included, so the result is still in the revenue and
 * expense accounts and has to be added here for the sheet to balance).
 *
 *   assets = liabilities + equity + current period result
 */
final class BalanceSheetReport
{
    /** @return array<string, mixed> */
    public function generate(int $fiscalYearId, ?string $date, int $level): array
    {
        try {
            $scope = ReportScope::resolve($fiscalYearId, null, $date);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['date' => $e->errors()['to'] ?? 'The date is not valid.']);
        }

        $assets = [];
        $liabilities = [];
        $equity = [];
        $unclassified = [];
        $result = 0;

        foreach (CategoryBalances::between($fiscalYearId, null, $scope['to'], $level) as $row) {
            $item = ['accountId' => $row['accountId'], 'code' => $row['code'], 'name' => $row['name'], 'level' => $level];

            switch ($row['category']) {
                case 'asset':
                    $assets[] = $item + ['amount' => $row['net']];
                    break;
                case 'liability':
                    $liabilities[] = $item + ['amount' => -$row['net']];
                    break;
                case 'equity':
                    $equity[] = $item + ['amount' => -$row['net']];
                    break;
                case 'revenue':
                case 'expense':
                    $result -= $row['net']; // revenue (credit) adds to the result, expenses (debit) reduce it
                    break;
                default:
                    $unclassified[] = ['accountId' => $row['accountId'], 'code' => $row['code'], 'name' => $row['name'], 'balance' => $row['net']];
            }
        }

        $totalAssets = array_sum(array_column($assets, 'amount'));
        $totalLiabilities = array_sum(array_column($liabilities, 'amount'));
        $totalEquity = array_sum(array_column($equity, 'amount'));
        $liabilitiesAndEquity = $totalLiabilities + $totalEquity + $result;
        $difference = $totalAssets - $liabilitiesAndEquity;

        $warnings = [];
        if ($unclassified !== []) {
            $warnings[] = [
                'code' => 'unclassified_accounts',
                'message' => 'Some accounts belong to groups without a category, so they are missing from the sheet and it does not balance. Classify the groups first.',
                'accounts' => $unclassified,
            ];
        }

        return [
            'fiscalYear' => ['id' => (int) $scope['year']->id, 'title' => $scope['year']->title],
            'date' => $scope['to'],
            'level' => $level,
            'assets' => ['rows' => $assets, 'total' => $totalAssets],
            'liabilities' => ['rows' => $liabilities, 'total' => $totalLiabilities],
            'equity' => ['rows' => $equity, 'total' => $totalEquity],
            'currentPeriodResult' => $result,
            'liabilitiesAndEquity' => $liabilitiesAndEquity,
            'difference' => $difference,
            'balanced' => $difference === 0,
            'complete' => $unclassified === [],
            'warnings' => $warnings,
        ];
    }
}
