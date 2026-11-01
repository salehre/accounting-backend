<?php

namespace Database\Seeders;

use App\Services\JournalEntryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A complete demo ledger for trying the year-end preview.
 * Development only: it also sets the accounting settings to the demo accounts.
 */
class YearEndDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('This demo seeder must not run in production.');

            return;
        }

        if (DB::table('fiscal_years')->where('title', 'DEMO-1399')->exists()) {
            $this->command?->info('The year-end demo data already exists.');

            return;
        }

        $adminId = (int) DB::table('users as u')
            ->join('roles as r', 'r.id', '=', 'u.role_id')
            ->where('r.name', 'admin')
            ->where('u.is_active', true)
            ->orderBy('u.id')
            ->value('u.id');

        if ($adminId === 0) {
            $this->command?->error('Create an administrator first: php artisan user:create');

            return;
        }

        DB::transaction(function () use ($adminId) {
            $year = DB::table('fiscal_years')->insertGetId([
                'title' => 'DEMO-1399',
                'start_date' => '2020-03-20',
                'end_date' => '2021-03-19',
            ]);
            DB::table('fiscal_years')->insert([
                'title' => 'DEMO-1400',
                'start_date' => '2021-03-20',
                'end_date' => '2022-03-19',
            ]);

            $account = function (string $code, string $name, int $level, string $nature, ?int $parent = null, bool $postable = false): int {
                return (int) DB::table('accounts')->insertGetId([
                    'parent_id' => $parent,
                    'code' => $code,
                    'name' => $name,
                    'level' => $level,
                    'nature' => $nature,
                    'is_postable' => $postable,
                ]);
            };
            $group = function (string $code, string $name, string $nature, string $category) use ($account): int {
                $id = $account($code, $name, 1, $nature);
                DB::table('account_group_categories')->insert(['account_id' => $id, 'category' => $category]);

                return $id;
            };

            $assets = $group('801', 'Demo assets', 'debit', 'asset');
            $equity = $group('802', 'Demo equity', 'credit', 'equity');
            $revenue = $group('803', 'Demo revenue', 'credit', 'revenue');
            $expenses = $group('804', 'Demo expenses', 'debit', 'expense');

            $assetsKol = $account('8011', 'Demo current assets', 2, 'debit', $assets);
            $equityKol = $account('8021', 'Demo capital', 2, 'credit', $equity);
            $revenueKol = $account('8031', 'Demo sales', 2, 'credit', $revenue);
            $expensesKol = $account('8041', 'Demo operating expenses', 2, 'debit', $expenses);

            $cash = $account('80111', 'Demo cash', 3, 'debit', $assetsKol, true);
            $capital = $account('80211', 'Demo capital', 3, 'credit', $equityKol, true);
            $retained = $account('80212', 'Demo retained earnings', 3, 'credit', $equityKol, true);
            $profitLoss = $account('80213', 'Demo profit and loss', 3, 'credit', $equityKol, true);
            $closing = $account('80214', 'Demo closing account', 3, 'credit', $equityKol, true);
            $opening = $account('80215', 'Demo opening account', 3, 'credit', $equityKol, true);
            $sales = $account('80311', 'Demo sales', 3, 'credit', $revenueKol, true);
            $rent = $account('80411', 'Demo rent', 3, 'debit', $expensesKol, true);

            DB::table('accounting_settings')->where('id', 1)->update([
                'profit_loss_account_id' => $profitLoss,
                'retained_earnings_account_id' => $retained,
                'closing_account_id' => $closing,
                'opening_account_id' => $opening,
            ]);

            $entries = app(JournalEntryService::class);
            $post = function (string $date, string $description, array $lines) use ($entries, $year, $adminId): void {
                $id = $entries->createDraft([
                    'fiscalYearId' => $year,
                    'entryDate' => $date,
                    'description' => $description,
                    'lines' => $lines,
                ], $adminId);
                $entries->post($id, $adminId);
            };

            $post('2020-04-01', 'Demo: capital', [
                ['accountId' => $cash, 'debit' => 1000000, 'credit' => 0],
                ['accountId' => $capital, 'debit' => 0, 'credit' => 1000000],
            ]);
            $post('2020-06-01', 'Demo: sale', [
                ['accountId' => $cash, 'debit' => 500000, 'credit' => 0],
                ['accountId' => $sales, 'debit' => 0, 'credit' => 500000],
            ]);
            $post('2020-09-01', 'Demo: rent', [
                ['accountId' => $rent, 'debit' => 200000, 'credit' => 0],
                ['accountId' => $cash, 'debit' => 0, 'credit' => 200000],
            ]);
        });

        $this->command?->info('Year-end demo data created: fiscal years DEMO-1399 and DEMO-1400, accounts 801.. and the settings.');
    }
}
