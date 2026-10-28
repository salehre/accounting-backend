<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoAccountingSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('This demo seeder must not run in production.');

            return;
        }

        if (! DB::table('fiscal_years')->where('title', '1405')->exists()) {
            DB::table('fiscal_years')->insert([
                'title' => '1405',
                'start_date' => '2026-03-21',
                'end_date' => '2027-03-20',
            ]);
        }

        // [code, name, level, nature, parent code, postable, requires detail]
        $accounts = [
            ['1', 'دارایی‌ها', 1, 'debit', null, false, false],
            ['11', 'دارایی‌های جاری', 2, 'debit', '1', false, false],
            ['1101', 'صندوق', 3, 'debit', '11', true, false],
            ['1102', 'بانک', 3, 'debit', '11', true, false],
            ['1103', 'مشتریان', 3, 'debit', '11', true, true],
            ['3', 'حقوق صاحبان سرمایه', 1, 'credit', null, false, false],
            ['31', 'سرمایه', 2, 'credit', '3', false, false],
            ['3101', 'سرمایه اولیه', 3, 'credit', '31', true, false],
            ['4', 'درآمدها', 1, 'credit', null, false, false],
            ['41', 'درآمد عملیاتی', 2, 'credit', '4', false, false],
            ['4101', 'فروش', 3, 'credit', '41', true, false],
            ['5', 'هزینه‌ها', 1, 'debit', null, false, false],
            ['51', 'هزینه‌های عملیاتی', 2, 'debit', '5', false, false],
            ['5101', 'هزینه اجاره', 3, 'debit', '51', true, false],
        ];

        $ids = DB::table('accounts')->pluck('id', 'code')->all();

        foreach ($accounts as [$code, $name, $level, $nature, $parentCode, $postable, $requiresDetail]) {
            if (isset($ids[$code])) {
                continue;
            }

            $ids[$code] = DB::table('accounts')->insertGetId([
                'parent_id' => $parentCode === null ? null : $ids[$parentCode],
                'code' => $code,
                'name' => $name,
                'level' => $level,
                'nature' => $nature,
                'is_postable' => $postable,
                'requires_detail' => $requiresDetail,
            ]);
        }

        DB::table('detail_accounts')->insertOrIgnore([
            'code' => '100001',
            'name' => 'مشتری نمونه',
            'kind' => 'customer',
        ]);
    }
}
