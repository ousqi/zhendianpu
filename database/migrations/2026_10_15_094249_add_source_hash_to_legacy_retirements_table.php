<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bookkeeping_legacy_retirements') || Schema::hasColumn('bookkeeping_legacy_retirements', 'source_hash')) {
            return;
        }

        Schema::table('bookkeeping_legacy_retirements', function (Blueprint $table): void {
            $table->json('source_snapshot')->nullable()->after('backup_reference');
            $table->char('source_hash', 64)->nullable()->after('source_snapshot');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bookkeeping_legacy_retirements') || ! Schema::hasColumn('bookkeeping_legacy_retirements', 'source_hash')) {
            return;
        }

        Schema::table('bookkeeping_legacy_retirements', function (Blueprint $table): void {
            $table->dropColumn(['source_hash', 'source_snapshot']);
        });
    }
};
