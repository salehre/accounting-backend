<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = DB::table('accounts')
            ->select(
                'id',
                'parent_id as parentId',
                'code',
                'name',
                'level',
                'nature',
                'is_postable as isPostable',
                'requires_detail as requiresDetail',
            )
            ->orderBy('code')
            ->get();

        return response()->json($accounts);
    }

    public function detailAccounts(): JsonResponse
    {
        $details = DB::table('detail_accounts')
            ->where('is_active', true)
            ->select('id', 'code', 'name', 'kind')
            ->orderBy('code')
            ->get();

        return response()->json($details);
    }
}
