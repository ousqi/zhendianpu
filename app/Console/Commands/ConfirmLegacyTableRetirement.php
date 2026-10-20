<?php

namespace App\Console\Commands;

use App\Services\Bookkeeping\LegacyTableSnapshot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

#[Signature('app:confirm-legacy-table-retirement {table : Non-cash legacy table to confirm} {--backup-reference= : Verified backup location or immutable reference} {--actor-id= : User ID recorded as the confirmer}')]
#[Description('Confirm a non-cash legacy table backup and immutable content snapshot before retirement')]
class ConfirmLegacyTableRetirement extends Command
{
    /** @var list<string> */
    private const LegacyBusinessTables = [
        'zdp_sales_records',
        'zdp_product_cost_versions',
        'zdp_purchase_records',
        'zdp_items',
        'zdp_suppliers',
        'zdp_categories',
        'zdp_projects',
        'zdp_shop_members',
        'zdp_shops',
    ];

    public function handle(): int
    {
        $table = (string) $this->argument('table');
        $backupReference = $this->option('backup-reference');
        $actorId = $this->option('actor-id');

        if (! in_array($table, self::LegacyBusinessTables, true)) {
            $this->error('Only non-cash legacy business tables can be confirmed with this command.');

            return self::FAILURE;
        }
        if (! Schema::hasTable($table)) {
            $this->error("Legacy table {$table} is unavailable. Run this before the retirement migration.");

            return self::FAILURE;
        }
        if (! is_string($backupReference) || trim($backupReference) === '') {
            $this->error('Provide --backup-reference.');

            return self::FAILURE;
        }
        if (! is_numeric($actorId) || ! DB::table('users')->where('id', (int) $actorId)->exists()) {
            $this->error('Provide an existing --actor-id so the confirmation is auditable.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('bookkeeping_legacy_retirements')) {
            $this->error('Run the reconciliation schema migration before confirming legacy tables.');

            return self::FAILURE;
        }

        $source = LegacyTableSnapshot::capture($table);
        $snapshot = json_decode($source['snapshot'], true, flags: JSON_THROW_ON_ERROR);

        DB::table('bookkeeping_legacy_retirements')->updateOrInsert(
            ['source_table' => $table],
            [
                'row_count' => $snapshot['row_count'],
                'backup_reference' => trim($backupReference),
                'source_snapshot' => $source['snapshot'],
                'source_hash' => $source['hash'],
                'confirmed_by' => (int) $actorId,
                'confirmed_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $this->info("Backup and immutable content snapshot confirmed for {$table}.");

        return self::SUCCESS;
    }
}
