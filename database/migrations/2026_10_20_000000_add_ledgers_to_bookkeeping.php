<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeping_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('type', 16);
            $table->string('currency', 3)->default('CNY');
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['status', 'type']);
        });

        $now = now();
        $ledgerId = DB::table('bookkeeping_ledgers')->insertGetId(['public_id' => (string) Str::ulid(), 'name' => '经营账本', 'type' => 'business', 'currency' => 'CNY', 'timezone' => 'Asia/Shanghai', 'status' => 'active', 'revision' => 1, 'created_at' => $now, 'updated_at' => $now]);

        Schema::table('bookkeeping_categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('ledger_id')->nullable()->after('id');
            $table->foreignId('parent_id')->nullable()->after('ledger_id')->constrained('bookkeeping_categories')->restrictOnDelete();
            // This column was removed by an earlier migration, but is required by
            // the ledger template and the ledger-scoped listing index below.
            $table->unsignedInteger('sort_order')->default(0)->after('direction');
        });
        Schema::table('bookkeeping_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('ledger_id')->nullable()->after('id');
        });
        Schema::table('bookkeeping_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('ledger_id')->nullable()->after('id');
        });
        Schema::table('bookkeeping_transfers', function (Blueprint $table): void {
            $table->unsignedBigInteger('ledger_id')->nullable()->after('id');
        });

        foreach (['bookkeeping_categories', 'bookkeeping_accounts', 'bookkeeping_entries', 'bookkeeping_transfers'] as $table) {
            DB::table($table)->whereNull('ledger_id')->update(['ledger_id' => $ledgerId]);
        }

        Schema::table('bookkeeping_categories', function (Blueprint $table): void {
            $table->unsignedBigInteger('ledger_id')->nullable(false)->change();
            $table->foreign('ledger_id')->references('id')->on('bookkeeping_ledgers')->restrictOnDelete();
            $table->dropUnique(['direction', 'name']);
            $table->unique(['ledger_id', 'direction', 'parent_id', 'name'], 'bookkeeping_categories_ledger_direction_parent_name_unique');
            $table->index(['ledger_id', 'direction', 'is_active', 'sort_order'], 'bookkeeping_categories_ledger_listing_index');
        });
        Schema::table('bookkeeping_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('ledger_id')->nullable(false)->change();
            $table->foreign('ledger_id')->references('id')->on('bookkeeping_ledgers')->restrictOnDelete();
            $table->dropUnique(['is_default']);
            $table->unique(['ledger_id', 'is_default'], 'bookkeeping_accounts_ledger_default_unique');
            $table->index(['ledger_id', 'is_active', 'sort_order'], 'bookkeeping_accounts_ledger_listing_index');
        });
        foreach (['bookkeeping_entries', 'bookkeeping_transfers'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->unsignedBigInteger('ledger_id')->nullable(false)->change();
                $table->foreign('ledger_id')->references('id')->on('bookkeeping_ledgers')->restrictOnDelete();
            });
        }
        Schema::table('bookkeeping_entries', function (Blueprint $table): void {
            $table->index(['ledger_id', 'occurred_on', 'direction'], 'bookkeeping_entries_ledger_occurred_direction_index');
        });
        Schema::table('bookkeeping_transfers', function (Blueprint $table): void {
            $table->index(['ledger_id', 'occurred_on', 'voided_at'], 'bookkeeping_transfers_ledger_occurred_voided_index');
        });
    }

    public function down(): void
    {
        // This migration intentionally retains ledger assignments and financial data on rollback.
    }
};
