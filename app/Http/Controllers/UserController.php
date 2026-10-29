<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Role;
use App\Services\UserService;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function roles(): JsonResponse
    {
        $roles = Role::query()
            ->orderBy('id')
            ->get(['id', 'name', 'permissions'])
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions,
            ])
            ->values();

        return response()->json($roles);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'isActive' => ['nullable', 'boolean'], // send 1 or 0
            'page' => ['nullable', 'integer', 'min:1'],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $isActive = $request->filled('isActive') ? $request->boolean('isActive') : null;

        return response()->json($this->users->paginate(
            $request->input('search'),
            $isActive,
            (int) $request->input('page', 1),
            (int) $request->input('pageSize', 25),
        ));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->users->find($id));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $id = $this->users->create($request->validated(), $request->user()->id);

        return response()->json($this->users->find($id), 201);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            abort(422, 'Nothing to update: send fullName, roleId and/or isActive.');
        }

        $this->users->update($id, $data, $request->user()->id);

        return response()->json($this->users->find($id));
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'newPassword' => PasswordPolicy::rules(),
        ]);

        $this->users->resetPassword($id, $data['newPassword'], $request->user()->id);

        return response()->json(['ok' => true]);
    }
}
