<?php

namespace App\Http\Controllers;

use App\Services\AccountingSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingSetupController extends Controller
{
    public function __construct(private readonly AccountingSetupService $setup) {}

    public function categories(): JsonResponse
    {
        return response()->json($this->setup->categories());
    }

    public function setCategory(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(AccountingSetupService::CATEGORIES)],
        ]);

        $this->setup->setCategory($id, $data['category'], $request->user()->id);

        return response()->json($this->setup->categories()->firstWhere('accountId', $id));
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->setup->settings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profitLossAccountId' => ['required', 'integer', 'exists:accounts,id'],
            'retainedEarningsAccountId' => ['required', 'integer', 'exists:accounts,id'],
            'closingAccountId' => ['required', 'integer', 'exists:accounts,id'],
            'openingAccountId' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        $this->setup->updateSettings($data, $request->user()->id);

        return response()->json($this->setup->settings());
    }
}
