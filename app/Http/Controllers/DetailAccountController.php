<?php

namespace App\Http\Controllers;

use App\Services\DetailAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DetailAccountController extends Controller
{
    private const CODE_RULE = '/^[A-Za-z0-9._-]{1,30}$/';

    public function __construct(private readonly DetailAccountService $details) {}

    /** Returns a plain array: search + limit work well for a dropdown with thousands of customers. */
    public function index(Request $request): JsonResponse
    {
        $query = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(DetailAccountService::KINDS)],
            'includeInactive' => ['nullable', 'boolean'], // send 1 for the management screen
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($this->details->search(
            $query['search'] ?? null,
            $query['kind'] ?? null,
            $request->boolean('includeInactive'),
            (int) ($query['limit'] ?? 50),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:'.self::CODE_RULE, Rule::unique('detail_accounts', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['nullable', Rule::in(DetailAccountService::KINDS)],
        ]);

        $id = $this->details->create($data, $request->user()->id);

        return response()->json($this->details->find($id), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:1', 'max:150'],
            'kind' => ['sometimes', Rule::in(DetailAccountService::KINDS)],
            'isActive' => ['sometimes', 'boolean'],
            'code' => ['sometimes', 'string', 'regex:'.self::CODE_RULE, Rule::unique('detail_accounts', 'code')->ignore($id)],
        ]);

        if ($data === []) {
            abort(422, 'Nothing to update.');
        }

        $this->details->update($id, $data, $request->user()->id);

        return response()->json($this->details->find($id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->details->delete($id, $request->user()->id);

        return response()->json(['ok' => true]);
    }
}
