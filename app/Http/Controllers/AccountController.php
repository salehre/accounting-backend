<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = DB::table('accounts')
            ->select('id', 'parent_id as parentId', 'code', 'name', 'level', 'nature', 'is_postable as isPostable')
            ->orderBy('code')
            ->get();

        return response()->json($accounts);
    }
}
