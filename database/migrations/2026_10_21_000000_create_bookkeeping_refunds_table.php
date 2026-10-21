<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookkeeping_refunds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('ledger_id')->constrained('bookkeeping_ledgers')->restrictOnDelete();
            $table->foreignId('entry_id')->constrained('bookkeeping_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('bookkeeping_accounts')->restrictOnDelete();
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
            $table->index(['ledger_id', 'entry_id', 'voided_at'], 'bookkeeping_refunds_ledger_entry_voided_index');
            $table->index(['ledger_id', 'occurred_on', 'voided_at'], 'bookkeeping_refunds_ledger_occurred_voided_index');
            $table->index(['account_id', 'voided_at'], 'bookkeeping_refunds_account_voided_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_refunds');
    }
};
