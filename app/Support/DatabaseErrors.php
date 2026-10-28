<?php

namespace App\Support;

use Illuminate\Database\QueryException;

final class DatabaseErrors
{
    /**
     * Maps a PostgreSQL error to an HTTP status and a readable message.
     *
     * @return array{status: int, message: string}|null
     */
    public static function map(QueryException $e): ?array
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $detail = (string) ($e->errorInfo[2] ?? '');

        return match ($sqlState) {
            // RAISE EXCEPTION inside our triggers/functions:
            // unbalanced entry, posted entry is immutable, closed fiscal year...
            'P0001' => [
                'status' => 422,
                'message' => self::cleanMessage($detail),
            ],
            // foreign key violation
            '23503' => [
                'status' => 400,
                'message' => 'A referenced record does not exist (account, detail account or fiscal year).',
            ],
            // check constraint violation
            '23514' => [
                'status' => 400,
                'message' => 'A value violates a database rule.',
            ],
            // unique violation
            '23505' => [
                'status' => 409,
                'message' => 'This record already exists (for example, the entry was already reversed).',
            ],
            default => null,
        };
    }

    // PostgreSQL gives "ERROR:  <message>\nCONTEXT: ..." - keep only the message.
    private static function cleanMessage(string $detail): string
    {
        $firstLine = strtok($detail, "\n");
        $message = trim((string) preg_replace('/^ERROR:\s*/', '', (string) $firstLine));

        return $message !== '' ? $message : 'A business rule was violated.';
    }
}
