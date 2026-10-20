<?php

namespace App\Services\Bookkeeping;

use JsonException;

class LegacyCashSnapshot
{
    /** @param array<string, mixed>|object $row @return array{snapshot: string, hash: string} */
    public static function capture(array|object $row): array
    {
        $snapshot = json_encode(self::canonical((array) $row), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return ['snapshot' => $snapshot, 'hash' => hash('sha256', $snapshot)];
    }

    /** @throws JsonException */
    public static function hash(string $snapshot): string
    {
        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

        return self::capture($decoded)['hash'];
    }

    /** @param array<array-key, mixed> $value @return array<array-key, mixed> */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }

        return $value;
    }
}
