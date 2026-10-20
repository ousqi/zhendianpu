<?php

use App\Services\Bookkeeping\LegacyCashSnapshot;
use App\Services\Bookkeeping\LegacyTableSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LegacyCashTable = 'zdp_cash_transactions';

    /** @var list<string> */
    private const LegacyBusinessTables = [
        'zdp_cash_transactions',
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

    public function up(): void
    {
        foreach (self::LegacyBusinessTables as $table) {
            $this->assertTableIsSafeToRetire($table);
        }

        foreach (self::LegacyBusinessTables as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Retired business tables contained historical records and cannot be recreated safely.
    }

    private function assertCashTransactionsAreSafeToRetire(): void
    {
        if (DB::table('bookkeeping_legacy_cash_reconciliations')->select('source_id')->where('source_table', self::LegacyCashTable)->groupBy('source_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Legacy cash reconciliation contains duplicate source mappings.');
        }
        if (DB::table('bookkeeping_legacy_cash_reconciliations')->select('entry_id')->where('source_table', self::LegacyCashTable)->where('status', 'imported')->whereNotNull('entry_id')->groupBy('entry_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Legacy cash reconciliation maps multiple source rows to one bookkeeping entry.');
        }

        if (DB::table('bookkeeping_legacy_cash_reconciliations as reconciliations')->leftJoin(self::LegacyCashTable.' as legacy', 'legacy.id', '=', 'reconciliations.source_id')
            ->where('reconciliations.source_table', self::LegacyCashTable)->whereNull('legacy.id')->exists()) {
            throw new RuntimeException('Legacy cash reconciliation contains mappings for missing source rows.');
        }

        DB::table(self::LegacyCashTable)->orderBy('id')->each(function (object $row): void {
            $reconciliations = DB::table('bookkeeping_legacy_cash_reconciliations')->where('source_table', self::LegacyCashTable)->where('source_id', $row->id)->get();
            if ($reconciliations->count() !== 1) {
                throw new RuntimeException('Every legacy cash row requires exactly one reconciliation.');
            }

            $reconciliation = $reconciliations->first();
            $sourceHash = LegacyCashSnapshot::capture($row)['hash'];
            if (blank($reconciliation->source_hash) || $reconciliation->source_hash !== $sourceHash || $reconciliation->source_hash !== LegacyCashSnapshot::hash($reconciliation->source_snapshot)) {
                throw new RuntimeException('Legacy cash reconciliation no longer matches its immutable source row.');
            }
            if (! $reconciliation->reconciled_at) {
                throw new RuntimeException('Every legacy cash reconciliation must be timestamped.');
            }

            if ($reconciliation->status === 'imported') {
                $entry = $reconciliation->entry_id ? DB::table('bookkeeping_entries')->where('id', $reconciliation->entry_id)->first() : null;
                $import = $entry ? DB::table('bookkeeping_legacy_imports')->where('source_table', self::LegacyCashTable)->where('source_id', $row->id)->where('entry_id', $entry->id)->exists() : false;
                if (! $entry || $entry->voided_at || ! $import || filled($reconciliation->exclusion_reason)) {
                    throw new RuntimeException('Imported legacy cash rows require an active matching bookkeeping entry.');
                }
                if ($entry->direction !== $this->entryDirection($row) || bccomp((string) $entry->amount, (string) $row->amount, 2) !== 0 || $entry->occurred_on !== $row->business_on) {
                    throw new RuntimeException('Imported legacy cash rows must preserve direction, amount, and date.');
                }

                return;
            }

            if ($reconciliation->status !== 'excluded' || $reconciliation->entry_id || ! in_array($reconciliation->exclusion_reason, ['legacy_row_voided', 'invalid_direction_or_amount'], true)) {
                throw new RuntimeException('Legacy cash exclusions require an allowed explicit reason.');
            }
        });
    }

    private function assertTableIsSafeToRetire(string $table): void
    {
        if (! Schema::hasTable($table) || ! DB::table($table)->exists()) {
            return;
        }

        if (! Schema::hasTable('bookkeeping_legacy_retirements')) {
            throw new RuntimeException('Create the retirement confirmation schema before retiring legacy business tables.');
        }

        $rowCount = DB::table($table)->count();
        $retirement = DB::table('bookkeeping_legacy_retirements')->where('source_table', $table)->first();
        if (! $retirement || (int) $retirement->row_count !== $rowCount || blank($retirement->backup_reference) || ! $retirement->confirmed_by || ! $retirement->confirmed_at) {
            if ($table === self::LegacyCashTable) {
                throw new RuntimeException('Reconcile and confirm every legacy cash row before retiring zhendianpu tables.');
            }

            throw new RuntimeException("Confirm the row count, backup, and retirement of every non-empty legacy table before dropping {$table}.");
        }

        if ($table !== self::LegacyCashTable) {
            if (blank($retirement->source_hash) || blank($retirement->source_snapshot) || $retirement->source_hash !== LegacyTableSnapshot::hash($retirement->source_snapshot) || $retirement->source_hash !== LegacyTableSnapshot::capture($table)['hash']) {
                throw new RuntimeException("Confirm an immutable content snapshot for {$table} before dropping it.");
            }

            return;
        }

        if (! Schema::hasTable('bookkeeping_legacy_cash_reconciliations')) {
            throw new RuntimeException('Create the reconciliation schema before retiring legacy cash transactions.');
        }

        $this->assertCashTransactionsAreSafeToRetire();
    }

    private function entryDirection(object $row): string
    {
        return $row->direction === 'in' ? 'income' : 'expense';
    }
};
