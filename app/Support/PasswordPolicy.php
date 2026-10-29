<?php


namespace App\Support;

final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    // bcrypt ignores everything after 72 bytes
    public const MAX_BYTES = 72;

    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'min:' . self::MIN_LENGTH,
            function (string $attribute, mixed $value, \Closure $fail): void {
                if (strlen((string)$value) > self::MAX_BYTES) {
                    $fail('The password is too long (maximum ' . self::MAX_BYTES . ' bytes).');
                }
            },
        ];
    }
}
