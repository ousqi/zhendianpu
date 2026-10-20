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
        Schema::create('bookkeeping_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('direction', 16);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['direction', 'name']);
            $table->index(['direction', 'is_active', 'sort_order']);
        });

        Schema::create('bookkeeping_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('direction', 16);
            $table->decimal('amount', 14, 2);
            $table->foreignId('category_id')->constrained('bookkeeping_categories')->restrictOnDelete();
            $table->date('occurred_on');
            $table->string('description', 1000)->nullable();
            $table->string('source', 32);
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['occurred_on', 'direction']);
            $table->index(['category_id', 'occurred_on']);
            $table->index(['voided_at', 'occurred_on']);
        });

        Schema::create('bookkeeping_legacy_imports', function (Blueprint $table): void {
            $table->id();
            $table->string('source_table');
            $table->unsignedBigInteger('source_id');
            $table->foreignId('entry_id')->constrained('bookkeeping_entries')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['source_table', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookkeeping_legacy_imports');
        Schema::dropIfExists('bookkeeping_entries');
        Schema::dropIfExists('bookkeeping_categories');
    }
};
