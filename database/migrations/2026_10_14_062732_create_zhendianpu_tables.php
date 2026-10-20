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
        Schema::create('zdp_shops', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        Schema::create('zdp_shop_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('zdp_shops')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role')->default('reader');
            $table->timestamps();
            $table->unique(['shop_id', 'user_id']);
        });
        Schema::create('zdp_projects', function (Blueprint $table): void {
            $this->common($table);
            $table->string('code', 64);
            $table->string('name');
            $table->string('status')->default('preparing');
            $table->date('started_on')->nullable();
            $table->json('settings')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['shop_id', 'code']);
        });
        Schema::create('zdp_categories', function (Blueprint $table): void {
            $this->common($table);
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('category_type');
            $table->string('default_profit_treatment')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unique(['shop_id', 'code']);
            $this->reference($table, 'parent_id', 'zdp_categories');
        });
        Schema::create('zdp_suppliers', function (Blueprint $table): void {
            $this->common($table);
            $table->string('code', 64);
            $table->string('name');
            $table->string('platform')->nullable();
            $table->text('shop_url')->nullable();
            $table->string('contact')->nullable();
            $table->json('aliases')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->unique(['shop_id', 'code']);
        });
        Schema::create('zdp_items', function (Blueprint $table): void {
            $this->common($table);
            $table->string('code', 64);
            $table->string('name');
            $table->json('aliases')->nullable();
            $table->string('item_type');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('standard_unit', 16);
            $table->json('default_spec')->nullable();
            $table->decimal('reference_sale_price', 14, 2)->nullable();
            $table->json('project_ids')->nullable();
            $table->json('reference_prices')->nullable();
            $table->string('status')->default('active');
            $table->text('notes')->nullable();
            $table->unique(['shop_id', 'code']);
            $this->reference($table, 'category_id', 'zdp_categories');
        });
        Schema::create('zdp_purchase_records', function (Blueprint $table): void {
            $this->common($table);
            $table->string('order_no', 64);
            $table->unsignedInteger('line_no');
            $table->string('record_kind')->default('purchase');
            $table->unsignedBigInteger('original_record_id')->nullable();
            $table->date('purchased_on');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->decimal('package_count', 18, 4)->nullable();
            $table->decimal('quantity_per_package', 18, 4)->nullable();
            $table->decimal('standard_quantity', 18, 4);
            $table->string('standard_unit', 16);
            foreach (['goods_amount', 'shipping_amount', 'discount_amount', 'net_amount', 'order_total_snapshot'] as $column) {
                $table->decimal($column, 14, 2)->nullable();
            }
            $table->decimal('unit_price_snapshot', 18, 8)->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->string('receipt_status')->default('pending');
            $table->string('split_status')->default('complete');
            $table->json('allocation_basis')->nullable();
            $table->json('voucher_ids')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['shop_id', 'order_no', 'line_no'], 'purchase_order_line_unique');
            $table->index(['shop_id', 'purchased_on', 'project_id'], 'purchase_shop_date_project');
            $this->reference($table, 'project_id', 'zdp_projects');
            $this->reference($table, 'item_id', 'zdp_items');
            $this->reference($table, 'supplier_id', 'zdp_suppliers');
            $this->reference($table, 'original_record_id', 'zdp_purchase_records');
        });
        Schema::create('zdp_product_cost_versions', function (Blueprint $table): void {
            $this->common($table);
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedInteger('version_no');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            foreach (['base_cost', 'extra_cost', 'normal_loss_reserve', 'final_unit_cost'] as $column) {
                $table->decimal($column, 18, 8)->nullable();
            }
            $table->json('recipe')->nullable();
            $table->text('basis');
            $table->string('status')->default('draft');
            $table->unique(['shop_id', 'product_id', 'version_no'], 'cost_product_version_unique');
            $table->index(['shop_id', 'product_id', 'status', 'effective_from'], 'cost_effective_lookup');
            $this->reference($table, 'product_id', 'zdp_items');
            $this->reference($table, 'project_id', 'zdp_projects');
        });
        Schema::create('zdp_sales_records', function (Blueprint $table): void {
            $this->common($table);
            $table->date('business_date');
            $table->unsignedBigInteger('project_id');
            $table->string('record_type');
            $table->string('entry_kind')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('original_record_id')->nullable();
            $table->unsignedBigInteger('day_summary_id')->nullable();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('gift_quantity', 18, 4)->default(0);
            $table->decimal('actual_unit_price', 14, 2)->nullable();
            $table->decimal('net_revenue', 14, 2)->default(0);
            $table->json('receipts')->nullable();
            $table->decimal('unit_cost_snapshot', 18, 8)->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->unsignedBigInteger('cost_version_id')->nullable();
            $table->json('cost_components')->nullable();
            $table->string('cost_precision')->default('unknown');
            $table->string('cost_mode')->default('unknown');
            $table->string('detail_coverage')->default('none');
            $table->string('closing_status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedTinyInteger('summary_guard')->storedAs("CASE WHEN record_type = 'summary' AND voided_at IS NULL THEN 1 ELSE NULL END");
            $table->unique(['shop_id', 'project_id', 'business_date', 'summary_guard'], 'sales_valid_summary_unique');
            $table->index(['shop_id', 'business_date', 'project_id'], 'sales_shop_date_project');
            $this->reference($table, 'project_id', 'zdp_projects');
            $this->reference($table, 'product_id', 'zdp_items');
            $this->reference($table, 'original_record_id', 'zdp_sales_records');
            $this->reference($table, 'day_summary_id', 'zdp_sales_records');
            $this->reference($table, 'cost_version_id', 'zdp_product_cost_versions');
        });
        Schema::create('zdp_cash_transactions', function (Blueprint $table): void {
            $this->common($table);
            $table->date('business_on');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('direction');
            $table->decimal('amount', 14, 2);
            $table->string('account_from', 64)->nullable();
            $table->string('account_to', 64)->nullable();
            $table->string('profit_treatment');
            $table->string('source_type')->nullable();
            $table->string('source_business_id', 64)->nullable();
            $table->ulid('source_event_id');
            $table->unsignedInteger('event_line_no');
            $table->unsignedBigInteger('original_transaction_id')->nullable();
            $table->json('allocation_snapshot')->nullable();
            $table->json('voucher_ids')->nullable();
            $table->text('notes')->nullable();
            $table->unique(['shop_id', 'source_event_id', 'event_line_no'], 'cash_event_line_unique');
            $table->index(['shop_id', 'business_on', 'project_id'], 'cash_shop_date_project');
            $this->reference($table, 'project_id', 'zdp_projects');
            $this->reference($table, 'category_id', 'zdp_categories');
            $this->reference($table, 'original_transaction_id', 'zdp_cash_transactions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['zdp_cash_transactions', 'zdp_sales_records', 'zdp_product_cost_versions', 'zdp_purchase_records', 'zdp_items', 'zdp_suppliers', 'zdp_categories', 'zdp_projects', 'zdp_shop_members', 'zdp_shops'] as $name) {
            Schema::dropIfExists($name);
        }
    }

    private function common(Blueprint $table): void
    {
        $table->id();
        $table->ulid('public_id')->unique();
        $table->foreignId('shop_id')->constrained('zdp_shops')->restrictOnDelete();
        $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
        $table->unsignedInteger('revision')->default(1);
        $table->timestamp('voided_at')->nullable();
        $table->text('void_reason')->nullable();
        $table->timestamps();
        $table->unique(['shop_id', 'id']);
    }

    private function reference(Blueprint $table, string $column, string $target): void
    {
        $table->foreign(['shop_id', $column])->references(['shop_id', 'id'])->on($target)->restrictOnDelete();
    }
};
