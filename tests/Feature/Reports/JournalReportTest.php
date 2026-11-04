<?php

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

class JournalReportTest extends TestCase
{
    use BuildsReportScenario;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminToken = $this->tokenFor($this->actor);
        $this->buildChart();
        $this->configureSettings();
        $this->reportScenario(); // numbers 1, 2 and 3 on 2026-04-01, 2026-06-01 and 2026-09-01
    }

    /** @param array<string, mixed> $query */
    private function journal(array $query = [], ?string $token = null): TestResponse
    {
        return $this->api(
            'GET',
            '/api/reports/journal?'.http_build_query(['fiscalYearId' => $this->year] + $query),
            [],
            $token ?? $this->adminToken,
        );
    }

    /** @param array<int, array<string, mixed>> $items */
    private function numbers(array $items): array
    {
        return array_column($items, 'number');
    }

    // -----------------------------------------------------------------
    // The book
    // -----------------------------------------------------------------

    public function test_the_whole_year(): void
    {
        $response = $this->journal()->assertOk()
            ->assertJsonPath('from', '2026-03-21')
            ->assertJsonPath('to', '2027-03-20')
            ->assertJsonPath('totals.debit', 1700000)
            ->assertJsonPath('totals.credit', 1700000)
            ->assertJsonPath('nextCursor', null);

        $items = $response->json('items');

        $this->assertSame([1, 2, 3], $this->numbers($items));
        $this->assertSame(['2026-04-01', '2026-06-01', '2026-09-01'], array_column($items, 'date'));
        $this->assertSame(['manual', 'manual', 'manual'], array_column($items, 'sourceType'));

        // every entry is complete and balanced
        foreach ($items as $item) {
            $this->assertCount(2, $item['lines']);
            $this->assertSame($item['totalDebit'], $item['totalCredit']);
            $this->assertSame($item['totalDebit'], array_sum(array_column($item['lines'], 'debit')));
            $this->assertSame([1, 2], array_column($item['lines'], 'lineNo'));
        }

        $first = $items[0]['lines'];
        $this->assertSame(['1101', 'Cash', 1000000, 0], [$first[0]['accountCode'], $first[0]['accountName'], $first[0]['debit'], $first[0]['credit']]);
        $this->assertSame(['3101', 0, 1000000], [$first[1]['accountCode'], $first[1]['debit'], $first[1]['credit']]);
    }

    public function test_drafts_are_not_in_the_book(): void
    {
        $this->makeDraft([['cash', 100, 0], ['capital', 0, 100]]);

        $this->assertSame([1, 2, 3], $this->numbers($this->journal()->assertOk()->json('items')));
    }

    public function test_the_year_end_entries_are_in_the_book(): void
    {
        $this->closeTheYear($this->adminToken);

        $response = $this->journal()->assertOk();
        $items = $response->json('items');

        $this->assertSame([1, 2, 3, 4, 5, 6], $this->numbers($items));
        $this->assertSame(
            ['year_end_temporary', 'year_end_profit', 'year_end_closing'],
            array_slice(array_column($items, 'sourceType'), 3),
        );

        // the total of the book is the total of its entries
        $sum = array_sum(array_column($items, 'totalDebit'));
        $response->assertJsonPath('totals.debit', $sum)->assertJsonPath('totals.credit', $sum);

        // and it is the same as the trial balance with the closing entries
        $this->api('GET', "/api/reports/trial-balance?fiscalYearId={$this->year}&includeClosing=1", [], $this->adminToken)
            ->assertOk()
            ->assertJsonPath('totals.periodDebit', $sum)
            ->assertJsonPath('totals.periodCredit', $sum);

        // the opening entry belongs to the next year's book
        $next = $this->api('GET', "/api/reports/journal?fiscalYearId={$this->nextYear}", [], $this->adminToken)->assertOk();
        $this->assertSame(['year_end_opening'], array_column($next->json('items'), 'sourceType'));
        $this->assertSame([1], $this->numbers($next->json('items')));
    }

    // -----------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------

    public function test_dates_filter_the_book(): void
    {
        $response = $this->journal(['from' => '2026-06-01', 'to' => '2026-06-30'])->assertOk()
            ->assertJsonPath('totals.debit', 500000)
            ->assertJsonPath('totals.credit', 500000);

        $this->assertSame([2], $this->numbers($response->json('items')));

        $this->journal(['to' => '2026-03-31'])->assertOk()
            ->assertJsonPath('items', [])
            ->assertJsonPath('totals.debit', 0)
            ->assertJsonPath('nextCursor', null);
    }

    public function test_a_range_of_entry_numbers_filters_the_book(): void
    {
        $range = $this->journal(['fromNumber' => 2, 'toNumber' => 3])->assertOk()
            ->assertJsonPath('fromNumber', 2)
            ->assertJsonPath('toNumber', 3)
            ->assertJsonPath('totals.debit', 700000)
            ->assertJsonPath('totals.credit', 700000);
        $this->assertSame([2, 3], $this->numbers($range->json('items')));

        $this->assertSame([3], $this->numbers($this->journal(['fromNumber' => 3])->assertOk()->json('items')));
        $this->assertSame([1, 2], $this->numbers($this->journal(['toNumber' => 2])->assertOk()->json('items')));

        // numbers and dates together
        $both = $this->journal(['fromNumber' => 1, 'toNumber' => 3, 'from' => '2026-09-01'])->assertOk();
        $this->assertSame([3], $this->numbers($both->json('items')));
    }

    // -----------------------------------------------------------------
    // Pages
    // -----------------------------------------------------------------

    public function test_pages_continue_where_the_previous_one_ended(): void
    {
        $first = $this->journal(['pageSize' => 2])->assertOk()
            ->assertJsonPath('totals.debit', 1700000);

        $this->assertSame([1, 2], $this->numbers($first->json('items')));
        $cursor = $first->json('nextCursor');
        $this->assertIsString($cursor);

        // the totals describe the whole range on every page
        $second = $this->journal(['pageSize' => 2, 'cursor' => $cursor])->assertOk()
            ->assertJsonPath('totals.debit', 1700000)
            ->assertJsonPath('nextCursor', null);
        $this->assertSame([3], $this->numbers($second->json('items')));

        // a page of exactly the remaining size has no next page
        $this->journal(['pageSize' => 3])->assertOk()->assertJsonPath('nextCursor', null);
    }

    public function test_the_book_is_ordered_by_date_and_then_by_number(): void
    {
        $this->postEntry([['cash', 10, 0], ['capital', 0, 10]], '2026-10-05'); // number 4
        $this->postEntry([['cash', 20, 0], ['sales', 0, 20]], '2026-10-05');   // number 5
        $this->postEntry([['cash', 30, 0], ['sales', 0, 30]], '2026-05-01');   // number 6, but an earlier date

        // walking through the book one entry at a time visits every entry exactly once
        $numbers = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->journal(['pageSize' => 1] + ($cursor === null ? [] : ['cursor' => $cursor]))->assertOk();
            foreach ($page->json('items') as $item) {
                $numbers[] = $item['number'];
            }
            $cursor = $page->json('nextCursor');
            $pages++;
        } while ($cursor !== null && $pages < 20);

        $this->assertSame([1, 6, 2, 3, 4, 5], $numbers);
    }

    public function test_a_changed_cursor_is_refused(): void
    {
        $cursor = $this->journal(['pageSize' => 1])->json('nextCursor');
        [$payload, $signature] = explode('.', $cursor);

        // a forged position
        $forged = rtrim(strtr(base64_encode(json_encode(['d' => '2020-01-01', 'n' => 0])), '+/', '-_'), '=');

        foreach ([$forged.'.'.$signature, $payload.'.'.str_repeat('0', 64), $payload, 'garbage.garbage'] as $bad) {
            $this->journal(['cursor' => $bad])->assertStatus(422)->assertJsonValidationErrors('cursor');
        }
    }

    // -----------------------------------------------------------------
    // Input and access
    // -----------------------------------------------------------------

    public function test_invalid_requests_are_rejected(): void
    {
        $cases = [
            'a first number after the last number' => [['fromNumber' => 3, 'toNumber' => 2], 'toNumber'],
            'a first number of zero' => [['fromNumber' => 0], 'fromNumber'],
            'a page that is too big' => [['pageSize' => 101], 'pageSize'],
            'an empty page' => [['pageSize' => 0], 'pageSize'],
            'a start date outside the year' => [['from' => '2020-01-01'], 'from'],
            'an end date before the start' => [['from' => '2026-09-01', 'to' => '2026-06-01'], 'to'],
        ];

        foreach ($cases as $case => [$query, $errorKey]) {
            $response = $this->journal($query);

            $response->assertStatus(422);
            $this->assertArrayHasKey($errorKey, $response->json('errors') ?? [], "The case \"{$case}\" was not rejected for \"{$errorKey}\"");
        }

        $this->api('GET', '/api/reports/journal?fiscalYearId=999999', [], $this->adminToken)
            ->assertStatus(422)->assertJsonValidationErrors('fiscalYearId');
    }

    public function test_who_may_read_the_book(): void
    {
        $this->api('GET', "/api/reports/journal?fiscalYearId={$this->year}")->assertStatus(401);

        $this->journal([], $this->tokenFor($this->makeUser('viewer')))->assertOk();

        DB::table('roles')->insert(['name' => 'no_reports', 'permissions' => json_encode(['entries.read'])]);
        $this->journal([], $this->tokenFor($this->makeUser('no_reports')))->assertStatus(403);
    }
}
