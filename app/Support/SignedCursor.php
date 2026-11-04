<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** A paging cursor that cannot be forged: the position is signed with the application key. */
final class SignedCursor
{
    /** @param array<string, int|string> $position */
    public static function encode(array $position): string
    {
        $json = (string) json_encode($position);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=').'.'.self::sign($json);
    }

    /**
     * @param array<int, string> $keys the keys the position must contain
     * @return array<string, mixed>
     */
    public static function decode(string $cursor, array $keys): array
    {
        $invalid = fn () => ValidationException::withMessages(['cursor' => 'The cursor is not valid.']);

        $parts = explode('.', $cursor);
        if (count($parts) !== 2) {
            throw $invalid();
        }

        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($json === false || ! hash_equals(self::sign($json), $parts[1])) {
            throw $invalid();
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw $invalid();
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                throw $invalid();
            }
        }

        return $data;
    }

    private static function sign(string $json): string
    {
        return hash_hmac('sha256', $json, (string) config('app.key'));
    }
}
