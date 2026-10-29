<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permissions are enforced by the route middleware
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'fullName' => ['sometimes', 'string', 'min:1', 'max:150'],
            'roleId' => ['sometimes', 'integer', 'exists:roles,id'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
