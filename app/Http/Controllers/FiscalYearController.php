<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class FiscalYearController extends Controller
{
    public function index(): JsonResponse
    {
        $years = DB::table('fiscal_years')
            ->selectRaw('id, title, start_date as "startDate", end_date as "endDate", status')
            ->orderByDesc('start_date')
            ->get();

        return response()->json($years);
    }
}
