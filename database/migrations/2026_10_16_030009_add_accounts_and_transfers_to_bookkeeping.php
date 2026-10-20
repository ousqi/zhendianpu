<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bookkeeping_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type', 32);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->nullable()->unique();
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        $defaultAccountId = DB::table('bookkeeping_accounts')->insertGetId([
            'name' => '默认账户', 'type' => 'other', 'sort_order' => 0, 'is_active' => true, 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('bookkeeping_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->nullable()->after('category_id');
        });
        DB::table('bookkeeping_entries')->whereNull('account_id')->update(['account_id' => $defaultAccountId]);
        Schema::table('bookkeeping_entries', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->nullable(false)->change();
            $table->foreign('account_id')->references('id')->on('bookkeeping_accounts')->restrictOnDelete();
            $table->index(['account_id', 'occurred_on']);
        });
        Schema::create('bookkeeping_transfers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('source_account_id')->constrained('bookkeeping_accounts')->restrictOnDelete();
            $table->foreignId('destination_account_id')->constrained('bookkeeping_accounts')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('occurred_on');
            $table->string('description', 1000)->nullable();
            $table->string('source', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['occurred_on', 'voided_at']);
            $table->index(['source_account_id', 'occurred_on']);
            $table->index(['destination_account_id', 'occurred_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_transfers');
        Schema::table('bookkeeping_entries', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->dropIndex(['account_id', 'occurred_on']);
            $table->dropColumn('account_id');
        });
        Schema::dropIfExists('bookkeeping_accounts');
    }
};
