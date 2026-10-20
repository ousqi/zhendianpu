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
        Schema::table('bookkeeping_categories', function (Blueprint $table): void {
            $table->dropIndex(['direction', 'is_active', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookkeeping_categories', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')->default(0)->after('direction');
            $table->index(['direction', 'is_active', 'sort_order']);
        });
    }
};
