<?php

namespace App\Services\Bookkeeping;

use Illuminate\Support\Facades\DB;
use JsonException;

class LegacyTableSnapshot
{
    /** @return array{snapshot: string, hash: string} */
    public static function capture(string $table): array
    {
        $rowCount = 0;
        $context = hash_init('sha256');

        DB::table($table)->orderBy('id')->each(function (object $row) use (&$rowCount, $context): void {
            $rowSnapshot = LegacyCashSnapshot::capture($row);
            hash_update($context, strlen($rowSnapshot['hash']).':'.$rowSnapshot['hash']);
            $rowCount++;
        });

        $snapshot = json_encode([
            'algorithm' => 'sha256-canonical-row-chain-v1',
            'row_count' => $rowCount,
            'table' => $table,
            'content_hash' => hash_final($context),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return ['snapshot' => $snapshot, 'hash' => self::hash($snapshot)];
    }

    /** @throws JsonException */
    public static function hash(string $snapshot): string
    {
        return LegacyCashSnapshot::hash($snapshot);
    }
}
