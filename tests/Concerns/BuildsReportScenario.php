<?php

namespace Tests\Concerns;

use App\Services\JournalEntryService;
use Illuminate\Support\Facades\DB;

/**
 * The chart of BuildsYearEndLedger plus dated entries, for the report tests.
 */
trait BuildsReportScenario
{
    use BuildsYearEndLedger;

    /**
     * 2026-04-01 capital 1,000,000 | 2026-06-01 sale 500,000 | 2026-09-01 rent 200,000.
     * Cash ends at 1,300,000.
     */
    private function reportScenario(): void
    {
        $this->postEntry([['cash', 1000000, 0], ['capital', 0, 1000000]], '2026-04-01');
        $this->postEntry([['cash', 500000, 0], ['sales', 0, 500000]], '2026-06-01');
        $this->postEntry([['rent', 200000, 0], ['cash', 0, 200000]], '2026-09-01');
    }

    /**
     * An account that requires a detail account, with two customers, and one sale to each.
     *
     * @return array{customers:int, a:int, b:int}
     */
    private function customersScenario(): array
    {
        $kol = (int) DB::table('accounts')->where('code', '11')->value('id');
        $customers = $this->makeAccount('1103', 'Customers', 3, 'debit', $kol, true, true);
        $a = DB::table('detail_accounts')->insertGetId(['code' => 'CA', 'name' => 'Customer A', 'kind' => 'customer']);
        $b = DB::table('detail_accounts')->insertGetId(['code' => 'CB', 'name' => 'Customer B', 'kind' => 'customer']);

        $service = app(JournalEntryService::class);
        foreach ([[$a, 300000, '2026-07-01'], [$b, 100000, '2026-07-02']] as [$detail, $amount, $date]) {
            $id = $service->createDraft([
                'fiscalYearId' => $this->year,
                'entryDate' => $date,
                'description' => null,
                'lines' => [
                    ['accountId' => $customers, 'detailId' => $detail, 'debit' => $amount, 'credit' => 0],
                    ['accountId' => $this->a['sales'], 'debit' => 0, 'credit' => $amount],
                ],
            ], $this->actor->id);
            $service->post($id, $this->actor->id);
        }

        return ['customers' => $customers, 'a' => $a, 'b' => $b];
    }

    /** Generates and posts the year-end entries of the first fiscal year through the API. */
    private function closeTheYear(string $adminToken): void
    {
        $this->api('POST', "/api/fiscal-years/{$this->year}/year-end/generate", [], $adminToken)->assertStatus(201);
        $this->api('POST', "/api/fiscal-years/{$this->year}/year-end/post", [], $adminToken)->assertOk();
    }
}
