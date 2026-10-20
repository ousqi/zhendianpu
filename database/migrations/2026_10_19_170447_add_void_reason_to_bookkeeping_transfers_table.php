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
        Schema::table('bookkeeping_transfers', function (Blueprint $table): void {
            $table->string('void_reason', 1000)->nullable()->after('voided_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookkeeping_transfers', function (Blueprint $table): void {
            $table->dropColumn('void_reason');
        });
    }
};
