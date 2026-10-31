<?php

namespace App\Http\Controllers;

use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'activeOnly' => ['nullable', 'boolean'], // send 1 for the entry form
        ]);

        return response()->json($this->accounts->all($request->boolean('activeOnly')));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'level' => ['required', 'integer', 'in:1,2,3'],
            'parentId' => ['nullable', 'integer', 'exists:accounts,id'],
            'code' => ['required', 'string', 'regex:/^[0-9]{1,20}$/', Rule::unique('accounts', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'nature' => ['required', 'in:debit,credit,both'],
            'requiresDetail' => ['nullable', 'boolean'],
        ]);

        $id = $this->accounts->create($data, $request->user()->id);

        return response()->json($this->accounts->find($id), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            // the position in the tree never changes
            'level' => ['prohibited'],
            'parentId' => ['prohibited'],
            'name' => ['sometimes', 'string', 'min:1', 'max:150'],
            'isActive' => ['sometimes', 'boolean'],
            'code' => ['sometimes', 'string', 'regex:/^[0-9]{1,20}$/', Rule::unique('accounts', 'code')->ignore($id)],
            'nature' => ['sometimes', 'in:debit,credit,both'],
            'requiresDetail' => ['sometimes', 'boolean'],
        ]);

        if ($data === []) {
            abort(422, 'Nothing to update.');
        }

        $this->accounts->update($id, $data, $request->user()->id);

        return response()->json($this->accounts->find($id));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->accounts->delete($id, $request->user()->id);

        return response()->json(['ok' => true]);
    }
}
