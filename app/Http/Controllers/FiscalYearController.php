<?php

namespace App\Http\Controllers;

use App\Services\FiscalYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    public function __construct(private readonly FiscalYearService $years) {}

    public function index(): JsonResponse
    {
        return response()->json($this->years->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d', 'after:startDate'],
        ]);

        $result = $this->years->create($data, $request->user()->id);

        return response()->json(
            $this->years->find($result['id']) + ['warnings' => $result['warnings']],
            201,
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:100'],
            'startDate' => ['sometimes', 'date_format:Y-m-d'],
            'endDate' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        if ($data === []) {
            abort(422, 'Nothing to update.');
        }

        $warnings = $this->years->update($id, $data, $request->user()->id);

        return response()->json($this->years->find($id) + ['warnings' => $warnings]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->years->delete($id, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function closeCheck(int $id): JsonResponse
    {
        return response()->json($this->years->closeCheck($id));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $this->years->close($id, $request->user()->id);

        return response()->json($this->years->find($id));
    }

    public function reopen(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $this->years->reopen($id, $data['reason'], $request->user()->id);

        return response()->json($this->years->find($id));
    }
}
