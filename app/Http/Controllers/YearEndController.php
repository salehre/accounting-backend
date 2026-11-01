<?php

namespace App\Http\Controllers;

use App\Services\YearEndService;
use Illuminate\Http\JsonResponse;

class YearEndController extends Controller
{
    public function __construct(private readonly YearEndService $yearEnd) {}

    public function preview(int $id): JsonResponse
    {
        return response()->json($this->yearEnd->preview($id));
    }
}
