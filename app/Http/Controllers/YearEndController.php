<?php

namespace App\Http\Controllers;

use App\Services\YearEndService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class YearEndController extends Controller
{
    public function __construct(private readonly YearEndService $yearEnd) {}

    public function preview(int $id): JsonResponse
    {
        return response()->json($this->yearEnd->preview($id));
    }

    public function generate(Request $request, int $id): JsonResponse
    {
        $this->yearEnd->generate($id, $request->user()->id);

        return response()->json($this->yearEnd->preview($id), 201);
    }

    public function discard(Request $request, int $id): JsonResponse
    {
        $this->yearEnd->discard($id, $request->user()->id);

        return response()->json($this->yearEnd->preview($id));
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return response()->json($this->yearEnd->post($id, $request->user()->id));
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        return response()->json($this->yearEnd->reverse($id, $data['reason'], $request->user()->id));
    }
}
