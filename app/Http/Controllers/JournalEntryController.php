<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJournalEntryRequest;
use App\Services\JournalEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JournalEntryController extends Controller
{
    public function __construct(private readonly JournalEntryService $entries) {}

    public function index(Request $request): JsonResponse
    {
        $query = $request->validate([
            'fiscalYearId' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:draft,posted'],
            'page' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->entries->paginate(
            isset($query['fiscalYearId']) ? (int) $query['fiscalYearId'] : null,
            $query['status'] ?? null,
            (int) ($query['page'] ?? 1),
            (int) ($query['pageSize'] ?? 25),
        ));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->entries->find($id));
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $id = $this->entries->createDraft($request->validated(), $request->user()->id);

        return response()->json($this->entries->find($id), 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->assertNotYearEnd($id);
        $this->entries->deleteDraft($id, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $this->assertNotYearEnd($id);
        $this->entries->post($id, $request->user()->id);

        return response()->json($this->entries->find($id));
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $this->assertNotYearEnd($id);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $reversalId = $this->entries->reverse($id, $request->user()->id, $data['date'] ?? null);

        return response()->json($this->entries->find($reversalId), 201);
    }

    /** The four year-end entries are only handled together, from the fiscal year's year-end screen. */
    private function assertNotYearEnd(int $id): void
    {
        $type = DB::table('journal_entries')->where('id', $id)->value('source_type');

        if (is_string($type) && str_starts_with($type, 'year_end_')) {
            abort(422, 'Year-end entries are managed from the fiscal year screen, not one by one.');
        }
    }
}
