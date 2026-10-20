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
        Schema::table('bookkeeping_accounts', function (Blueprint $table): void {
            $table->decimal('opening_balance', 14, 2)->default(0)->after('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookkeeping_accounts', function (Blueprint $table): void {
            $table->dropColumn('opening_balance');
        });
    }
};
