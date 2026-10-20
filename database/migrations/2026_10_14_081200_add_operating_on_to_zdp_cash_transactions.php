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
        Schema::table('zdp_cash_transactions', function (Blueprint $table) {
            $table->date('operating_on')->nullable()->after('business_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zdp_cash_transactions', function (Blueprint $table) {
            $table->dropColumn('operating_on');
        });
    }
};
