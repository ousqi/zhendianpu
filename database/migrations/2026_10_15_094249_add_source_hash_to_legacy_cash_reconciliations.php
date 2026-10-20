<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookkeeping_legacy_cash_reconciliations')) {
            return;
        }

        if (! Schema::hasColumn('bookkeeping_legacy_cash_reconciliations', 'source_hash')) {
            Schema::table('bookkeeping_legacy_cash_reconciliations', function (Blueprint $table): void {
                $table->char('source_hash', 64)->nullable()->after('source_snapshot');
            });
        }

        // Existing rows are reconciled by the explicit command so their source
        // snapshot can be checked against the still-present legacy row first.
    }

    public function down(): void
    {
        if (Schema::hasTable('bookkeeping_legacy_cash_reconciliations') && Schema::hasColumn('bookkeeping_legacy_cash_reconciliations', 'source_hash')) {
            Schema::table('bookkeeping_legacy_cash_reconciliations', function (Blueprint $table): void {
                $table->dropColumn('source_hash');
            });
        }
    }
};
