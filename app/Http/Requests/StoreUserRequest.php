<?php


namespace App\Http\Requests;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permissions are enforced by the route middleware
    }

    protected function prepareForValidation(): void
    {
        // Usernames are case-insensitive: always stored in lower case.
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'username' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9._-]{2,49}$/',
                Rule::unique('users', 'username'),
            ],
            'fullName' => ['required', 'string', 'max:150'],
            'password' => PasswordPolicy::rules(),
            'roleId' => ['required', 'integer', 'exists:roles,id'],
        ];
    }
}
