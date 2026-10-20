<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->createReconciliationTables();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Retired business tables contained historical records and cannot be recreated safely.
    }

    private function createReconciliationTables(): void
    {
        if (! Schema::hasTable('bookkeeping_legacy_cash_reconciliations')) {
            Schema::create('bookkeeping_legacy_cash_reconciliations', function (Blueprint $table): void {
                $table->id();
                $table->string('source_table');
                $table->unsignedBigInteger('source_id');
                $table->string('source_type')->nullable();
                $table->string('source_business_id', 64)->nullable();
                $table->string('status', 16);
                $table->foreignId('entry_id')->nullable()->constrained('bookkeeping_entries')->restrictOnDelete();
                $table->string('exclusion_reason')->nullable();
                $table->json('source_snapshot');
                $table->char('source_hash', 64);
                $table->foreignId('reconciled_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamps();
                $table->unique(['source_table', 'source_id'], 'legacy_cash_source_unique');
            });
        }
        if (! Schema::hasTable('bookkeeping_legacy_retirements')) {
            Schema::create('bookkeeping_legacy_retirements', function (Blueprint $table): void {
                $table->id();
                $table->string('source_table')->unique();
                $table->unsignedBigInteger('row_count');
                $table->string('backup_reference');
                $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }
    }
};
