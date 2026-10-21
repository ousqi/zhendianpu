<?php

namespace Tests\Feature;

use App\Data\Mcp\AccessContext;
use App\Mcp\Servers\ZhendianpuServer;
use App\Mcp\Tools\Zhendianpu\AddCategory;
use App\Mcp\Tools\Zhendianpu\AddRefund;
use App\Mcp\Tools\Zhendianpu\ListCategories;
use App\Mcp\Tools\Zhendianpu\ListRefunds;
use App\Mcp\Tools\Zhendianpu\ToggleCategory;
use App\Mcp\Tools\Zhendianpu\UpdateCategory;
use App\Mcp\Tools\Zhendianpu\UpdateRefund;
use App\Mcp\Tools\Zhendianpu\VoidRefund;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Transfer;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bookkeeping\BookkeepingAccountService;
use App\Services\Bookkeeping\BookkeepingCategoryService;
use App\Services\Bookkeeping\BookkeepingQueryService;
use App\Services\Bookkeeping\BookkeepingRefundService;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\BookkeepingTransferService;
use App\Services\Bookkeeping\LedgerService;
use App\Services\Bookkeeping\LegacyCashSnapshot;
use App\Services\Bookkeeping\LegacyTableSnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class BookkeepingServiceTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'users',
        'workspaces',
        'workspace_members',
        'mcp_servers',
        'mcp_server_access',
        'mcp_operations',
        'audit_logs',
        'bookkeeping_ledgers',
        'bookkeeping_categories',
        'bookkeeping_accounts',
        'bookkeeping_entries',
        'bookkeeping_refunds',
        'bookkeeping_transfers',
        'bookkeeping_legacy_imports',
        'bookkeeping_legacy_cash_reconciliations',
        'bookkeeping_legacy_retirements',
    ];

    private Ledger $ledger;

    protected function tearDown(): void
    {
        foreach (['zdp_cash_transactions', 'zdp_sales_records', 'zdp_product_cost_versions', 'zdp_purchase_records', 'zdp_items', 'zdp_suppliers', 'zdp_categories', 'zdp_projects', 'zdp_shop_members', 'zdp_shops'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function context(string $role = 'manager'): AccessContext
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $server = McpServer::factory()->create(['module_key' => 'zhendianpu']);
        $this->ledger = Ledger::factory()->create(['name' => '经营账本']);
        DB::table('workspace_members')->insert(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mcp_server_access')->insert(['workspace_id' => $workspace->id, 'server_id' => $server->id, 'user_id' => $user->id, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return new AccessContext($user, $server, [['id' => $workspace->id, 'public_id' => $workspace->public_id, 'name' => $workspace->name, 'role' => $role]], (string) Str::ulid());
    }

    public function test_creates_atomic_entries_and_replays_an_identical_event(): void
    {
        $context = $this->context();
        $expense = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $income = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        $input = ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entries' => [['direction' => 'expense', 'amount' => '180.00', 'category_id' => $expense->id], ['direction' => 'income', 'amount' => '1200.00', 'category_id' => $income->id]]];

        $first = app(BookkeepingService::class)->add($context, $input);
        $second = app(BookkeepingService::class)->add($context, $input);

        $this->assertSame(false, $first['replayed']);
        $this->assertSame(true, $second['replayed']);
        $this->assertDatabaseCount('bookkeeping_entries', 2);
    }

    public function test_rejects_mismatched_categories_future_dates_and_invalid_batch_without_writes(): void
    {
        $this->travelTo(Carbon::parse('2026-10-15 10:00:00', 'Asia/Shanghai'));
        $context = $this->context();
        $income = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);

        try {
            app(BookkeepingService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entries' => [['direction' => 'expense', 'amount' => '1.00', 'category_id' => $income->id, 'occurred_on' => '2026-10-16']]]);
            $this->fail('Expected invalid category exception.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('bookkeeping_entries', 0);
        }
    }

    public function test_void_excludes_entry_from_summary_and_stale_revision_is_refused(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '100.00', 'category_id' => $category->id], 'admin');
        app(BookkeepingService::class)->void($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'revision' => 1]);

        $summary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id]);
        $this->assertSame('0.00', $summary['totals']['expense']);
        $this->expectException(ValidationException::class);
        app(BookkeepingService::class)->update($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'revision' => 1, 'description' => 'stale']);
    }

    public function test_summary_excludes_voided_entries_even_when_the_list_includes_them(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $service = app(BookkeepingService::class);
        $active = $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '9999999999.99', 'category_id' => $category->id], 'chatgpt');
        $voided = $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '1.01', 'category_id' => $category->id], 'chatgpt');
        $service->void($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $voided->public_id, 'revision' => 1]);

        $result = app(BookkeepingQueryService::class)->entries($context, ['ledger_id' => $this->ledger->public_id, 'include_voided' => true]);

        $this->assertCount(2, $result['records']);
        $this->assertSame('9999999999.99', $result['summary']['expense']);
        $this->assertSame($active->public_id, $result['records'][1]['id']);
    }

    public function test_summary_reports_expense_percentages_using_displayed_amount_precision(): void
    {
        $context = $this->context();
        $first = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $second = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $service = app(BookkeepingService::class);
        $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '1.00', 'category_id' => $first->id], 'admin');
        $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '2.00', 'category_id' => $second->id], 'admin');

        $summary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id]);
        $percentages = collect($summary['categories'])->pluck('percentage', 'id');

        $this->assertSame('33.33', $percentages[$first->id]);
        $this->assertSame('66.66', $percentages[$second->id]);
    }

    public function test_summary_returns_filtered_totals_and_category(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'name' => '食材', 'direction' => 'expense']);
        $otherCategory = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $service = app(BookkeepingService::class);
        $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '12.50', 'category_id' => $category->id], 'admin');
        $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '20.00', 'category_id' => $otherCategory->id], 'admin');

        $summary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id, 'direction' => 'expense', 'category_id' => $category->id]);

        $this->assertSame(['income' => '0.00', 'expense' => '12.50', 'balance' => '-12.50'], $summary['totals']);
        $this->assertSame([['id' => $category->id, 'name' => '食材', 'direction' => 'expense', 'amount' => '12.50', 'percentage' => '100.00']], $summary['categories']);
    }

    public function test_filtered_totals_exclude_voided_entries(): void
    {
        $context = $this->context();
        $first = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $second = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $service = app(BookkeepingService::class);
        $active = $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '12.50', 'category_id' => $first->id], 'admin');
        $voided = $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '8.00', 'category_id' => $first->id], 'admin');
        $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '20.00', 'category_id' => $second->id], 'admin');
        $service->void($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $voided->public_id, 'revision' => 1]);

        $totals = app(BookkeepingQueryService::class)->totalsFor(Entry::query()->where('category_id', $first->id));

        $this->assertSame('12.50', $totals['expense']);
        $this->assertSame('0.00', $totals['income']);
        $this->assertSame('-12.50', $totals['balance']);
        $this->assertSame($active->id, Entry::query()->where('category_id', $first->id)->whereNull('voided_at')->sole()->id);
    }

    public function test_bookkeeping_access_requires_exactly_one_authorized_workspace(): void
    {
        $context = $this->context();

        $this->assertTrue(app(BookkeepingService::class)->canAccess($context->user));

        $workspace = Workspace::factory()->create();
        DB::table('workspace_members')->insert(['workspace_id' => $workspace->id, 'user_id' => $context->user->id, 'role' => 'reader', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('mcp_server_access')->insert(['workspace_id' => $workspace->id, 'server_id' => $context->server->id, 'user_id' => $context->user->id, 'role' => 'reader', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertFalse(app(BookkeepingService::class)->canAccess($context->user));
    }

    public function test_update_can_clear_description_without_changing_creation_source(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '10.00', 'category_id' => $category->id, 'description' => '原说明'], 'chatgpt');

        app(BookkeepingService::class)->update($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'revision' => 1, 'description' => null], 'admin');

        $entry->refresh();
        $this->assertNull($entry->description);
        $this->assertSame('chatgpt', $entry->source);
        $this->assertSame(2, $entry->revision);
    }

    public function test_category_changes_are_audited_and_used_category_direction_cannot_change(): void
    {
        $context = $this->context();
        $service = app(BookkeepingCategoryService::class);
        $category = $service->create($context, $this->ledger, ['name' => '临时支出', 'direction' => 'expense']);
        app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '10.00', 'category_id' => $category->id], 'admin');

        try {
            $service->update($context, $this->ledger, $category, ['name' => '临时收入', 'direction' => 'income']);
            $this->fail('Expected a category direction validation exception.');
        } catch (ValidationException) {
            $category = $service->update($context, $this->ledger, $category, ['name' => '已改名支出', 'direction' => 'expense']);
            $service->toggle($context, $this->ledger, $category);
        }

        $this->assertSame('已改名支出', $category->name);
        $this->assertFalse($category->refresh()->is_active);
        $this->assertDatabaseCount('audit_logs', 3);
    }

    public function test_deletes_an_unreferenced_leaf_category(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);

        app(BookkeepingCategoryService::class)->delete($context, $this->ledger, $category);

        $this->assertModelMissing($category);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bookkeeping.category.delete', 'entity_id' => (string) $category->id]);
    }

    public function test_rejects_deleting_a_category_from_another_ledger(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => Ledger::factory()->create()->id, 'direction' => 'expense']);

        try {
            app(BookkeepingCategoryService::class)->delete($context, $this->ledger, $category);
            $this->fail('Expected cross-ledger category deletion to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['分类不属于当前账本。'], $exception->errors()['category']);
        }

        $this->assertModelExists($category);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_rejects_deleting_a_category_associated_with_bookkeeping_entries(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '10.00', 'category_id' => $category->id], 'admin');

        try {
            app(BookkeepingCategoryService::class)->delete($context, $this->ledger, $category);
            $this->fail('Expected referenced category deletion to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['已被收支记录引用的分类不能删除。'], $exception->errors()['category']);
        }

        $this->assertModelExists($category);
    }

    public function test_rejects_deleting_a_parent_category_with_children(): void
    {
        $context = $this->context();
        $parent = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        Category::factory()->create(['ledger_id' => $this->ledger->id, 'parent_id' => $parent->id, 'direction' => 'expense']);

        try {
            app(BookkeepingCategoryService::class)->delete($context, $this->ledger, $parent);
            $this->fail('Expected parent category deletion to fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['含有子分类的父分类不能删除。'], $exception->errors()['category']);
        }

        $this->assertModelExists($parent);
    }

    public function test_category_management_tools_are_registered(): void
    {
        ZhendianpuServer::tools()->assertRegistered([
            ListCategories::class,
            AddCategory::class,
            UpdateCategory::class,
            ToggleCategory::class,
        ]);
    }

    public function test_category_management_tools_create_list_update_and_toggle_categories(): void
    {
        $context = $this->context();
        request()->attributes->set('mcp.context', $context);

        ZhendianpuServer::tool(AddCategory::class, [
            'ledger_id' => $this->ledger->public_id,
            'name' => '临时收入',
            'direction' => 'income',
        ])->assertOk()->assertStructuredContent(function ($response): void {
            $response->where('ok', true)
                ->where('category.name', '临时收入')
                ->where('category.direction', 'income')
                ->where('category.is_active', true)
                ->etc();
        });

        $category = Category::query()->sole();

        ZhendianpuServer::tool(ListCategories::class, ['ledger_id' => $this->ledger->public_id, 'direction' => 'income'])
            ->assertOk()
            ->assertStructuredContent(function ($response) use ($category): void {
                $response->where('categories.0.id', $category->id)
                    ->where('categories.0.name', '临时收入')
                    ->where('categories.0.is_active', true)
                    ->etc();
            });

        ZhendianpuServer::tool(UpdateCategory::class, [
            'ledger_id' => $this->ledger->public_id,
            'category_id' => $category->id,
            'name' => '已改名收入',
            'direction' => 'income',
        ])->assertOk();

        ZhendianpuServer::tool(ToggleCategory::class, ['ledger_id' => $this->ledger->public_id, 'category_id' => $category->id])->assertOk();

        $this->assertDatabaseHas('bookkeeping_categories', [
            'id' => $category->id,
            'name' => '已改名收入',
            'is_active' => false,
        ]);
    }

    public function test_default_account_is_created_automatically_and_cannot_be_disabled_or_deleted(): void
    {
        $context = $this->context();
        $service = app(BookkeepingAccountService::class);
        $default = $service->defaultAccount($this->ledger);

        $this->assertTrue($default->is_default);
        $this->expectException(ValidationException::class);
        $service->update($context, $this->ledger, $default, ['name' => $default->name, 'type' => $default->type, 'sort_order' => 0, 'is_active' => false]);
    }

    public function test_non_default_accounts_are_null_and_the_unique_default_constraint_rejects_duplicates(): void
    {
        $context = $this->context();
        $service = app(BookkeepingAccountService::class);
        $service->defaultAccount($this->ledger);
        $first = $service->create($context, $this->ledger, ['name' => '现金', 'type' => 'cash', 'sort_order' => 1, 'is_active' => true]);
        $second = $service->create($context, $this->ledger, ['name' => '银行卡', 'type' => 'bank_card', 'sort_order' => 2, 'is_active' => true]);

        $this->assertDatabaseHas('bookkeeping_accounts', ['id' => $first->id, 'is_default' => null]);
        $this->assertDatabaseHas('bookkeeping_accounts', ['id' => $second->id, 'is_default' => null]);

        $this->expectException(QueryException::class);
        Account::factory()->create(['ledger_id' => $this->ledger->id, 'is_default' => true]);
    }

    public function test_setting_defaults_sequentially_leaves_only_the_last_active_account_as_default(): void
    {
        $context = $this->context();
        $service = app(BookkeepingAccountService::class);
        $service->defaultAccount($this->ledger);
        $first = $service->create($context, $this->ledger, ['name' => '现金', 'type' => 'cash', 'sort_order' => 1, 'is_active' => true]);
        $second = $service->create($context, $this->ledger, ['name' => '银行卡', 'type' => 'bank_card', 'sort_order' => 2, 'is_active' => true]);

        $service->setDefault($context, $this->ledger, $first);
        $service->setDefault($context, $this->ledger, $second);

        $this->assertSame($second->id, Account::query()->where('is_default', true)->sole()->id);
        $this->assertDatabaseCount('bookkeeping_accounts', 3);
    }

    public function test_default_or_referenced_account_cannot_be_deleted(): void
    {
        $context = $this->context();
        $accounts = app(BookkeepingAccountService::class);
        $default = $accounts->defaultAccount($this->ledger);
        $referenced = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '1.00', 'category_id' => $category->id, 'account_id' => $referenced->id], 'admin');

        try {
            $accounts->delete($context, $this->ledger, $default);
            $this->fail('Expected default account deletion to fail.');
        } catch (ValidationException) {
            $this->expectException(ValidationException::class);
            $accounts->delete($context, $this->ledger, $referenced);
        }
    }

    public function test_entries_default_to_the_default_account_and_filter_by_account(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '10.00', 'category_id' => $category->id], 'admin');

        $result = app(BookkeepingQueryService::class)->entries($context, ['ledger_id' => $this->ledger->public_id, 'account_id' => $entry->account_id]);
        $this->assertSame($entry->account_id, $result['records'][0]['account_id']);
    }

    public function test_transfers_are_idempotent_voidable_and_do_not_change_entry_summary(): void
    {
        $context = $this->context();
        $source = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $destination = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $input = ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'source_account_id' => $source->id, 'destination_account_id' => $destination->id, 'amount' => '12.50'];
        $service = app(BookkeepingTransferService::class);

        $first = $service->add($context, $input);
        $second = $service->add($context, $input);
        $service->void($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'transfer_id' => $first['transfer']['id'], 'revision' => 1, 'void_reason' => '重复转账']);

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame('0.00', app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id])['totals']['balance']);
        $this->assertDatabaseHas('bookkeeping_transfers', ['id' => 1, 'voided_by' => $context->user->id, 'void_reason' => '重复转账']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bookkeeping.transfer.void', 'entity_id' => $first['transfer']['id']]);
    }

    public function test_transfer_updates_are_revisioned_idempotent_and_excluded_from_entry_summary(): void
    {
        $context = $this->context();
        $accounts = app(BookkeepingAccountService::class);
        $source = $accounts->defaultAccount($this->ledger);
        $destination = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $replacement = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $service = app(BookkeepingTransferService::class);
        $created = $service->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'source_account_id' => $source->id, 'destination_account_id' => $destination->id, 'amount' => '12.50']);
        $input = ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'transfer_id' => $created['transfer']['id'], 'revision' => 1, 'source_account_id' => $replacement->id, 'destination_account_id' => $source->id, 'amount' => '20.00', 'occurred_on' => '2026-10-15', 'description' => '调整后'];

        $updated = $service->update($context, $input);
        $replayed = $service->update($context, $input);

        $this->assertSame(2, $updated['transfer']['revision']);
        $this->assertSame('20.00', $updated['transfer']['amount']);
        $this->assertSame($replacement->id, $updated['transfer']['source_account_id']);
        $this->assertSame('调整后', $updated['transfer']['description']);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame('0.00', app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id])['totals']['balance']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bookkeeping.transfer.update', 'entity_id' => $created['transfer']['id']]);

        $this->expectException(ValidationException::class);
        $service->update($context, [...$input, 'event_id' => (string) Str::ulid()]);
    }

    public function test_transfer_update_rejects_requests_without_editable_fields_without_writes(): void
    {
        $context = $this->context();
        $source = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $destination = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $created = app(BookkeepingTransferService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'source_account_id' => $source->id, 'destination_account_id' => $destination->id, 'amount' => '12.50']);

        try {
            app(BookkeepingTransferService::class)->update($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'transfer_id' => $created['transfer']['id'], 'revision' => 1]);
            $this->fail('Expected an editable field validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(['至少提供一个可编辑字段。'], $exception->errors()['arguments']);
        }

        $this->assertSame(1, Transfer::query()->where('public_id', $created['transfer']['id'])->sole()->revision);
        $this->assertDatabaseCount('mcp_operations', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_writes_reject_inactive_accounts(): void
    {
        $context = $this->context();
        $inactive = Account::factory()->create(['ledger_id' => $this->ledger->id, 'is_active' => false]);
        $active = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);

        try {
            app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '10.00', 'category_id' => $category->id, 'account_id' => $inactive->id], 'admin');
            $this->fail('Expected inactive account validation exception.');
        } catch (ValidationException) {
            $this->expectException(ValidationException::class);
            app(BookkeepingTransferService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'source_account_id' => $active->id, 'destination_account_id' => $inactive->id, 'amount' => '1.00']);
        }
    }

    public function test_ledger_entries_are_isolated_from_other_ledgers(): void
    {
        $context = $this->context();
        $otherLedger = Ledger::factory()->create();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        $otherCategory = Category::factory()->create(['ledger_id' => $otherLedger->id, 'direction' => 'income']);
        $service = app(BookkeepingService::class);
        $service->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '10.00', 'category_id' => $category->id], 'admin');
        $service->createEntry($context->user, $otherLedger, ['direction' => 'income', 'amount' => '20.00', 'category_id' => $otherCategory->id], 'admin');

        $summary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id]);

        $this->assertSame('10.00', $summary['totals']['income']);
        $this->assertDatabaseCount('bookkeeping_entries', 2);
    }

    public function test_current_ledger_selection_persists_after_session_is_cleared_and_archived_selection_falls_back(): void
    {
        $context = $this->context();
        $otherLedger = Ledger::factory()->create(['name' => '个人账本']);
        $service = app(LedgerService::class);

        $service->selectCurrent($context->user, $otherLedger);

        $this->assertSame($otherLedger->id, $service->current($context->user)->id);
        $this->assertDatabaseHas('users', ['id' => $context->user->id, 'bookkeeping_default_ledger_id' => $otherLedger->id]);

        session()->forget('bookkeeping.current_ledger_id.'.$context->user->id);
        $user = User::query()->findOrFail($context->user->id);

        $this->assertSame($otherLedger->id, $service->current($user)->id);

        $otherLedger->update(['status' => 'archived']);
        $this->assertSame('archived', $otherLedger->fresh()->status);
        session()->forget('bookkeeping.current_ledger_id.'.$user->id);
        $fallback = $service->fallback();

        $this->assertSame($fallback->id, $service->current($user)->id);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'bookkeeping_default_ledger_id' => $fallback->id]);
    }

    public function test_entries_reject_parent_categories_without_writes(): void
    {
        $context = $this->context();
        $parent = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        Category::factory()->create(['ledger_id' => $this->ledger->id, 'parent_id' => $parent->id, 'direction' => 'expense']);

        try {
            app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '10.00', 'category_id' => $parent->id], 'admin');
            $this->fail('Expected parent category validation exception.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('bookkeeping_entries', 0);
        }
    }

    public function test_transfers_reject_accounts_from_another_ledger_without_writes(): void
    {
        $context = $this->context();
        $source = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $otherAccount = Account::factory()->create(['ledger_id' => Ledger::factory()->create()->id]);

        try {
            app(BookkeepingTransferService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'source_account_id' => $source->id, 'destination_account_id' => $otherAccount->id, 'amount' => '1.00']);
            $this->fail('Expected cross-ledger transfer validation exception.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('bookkeeping_transfers', 0);
        }
    }

    public function test_archived_ledger_rejects_entry_writes_without_creating_operations(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $this->ledger->update(['status' => 'archived']);

        try {
            app(BookkeepingService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entries' => [['direction' => 'expense', 'amount' => '10.00', 'category_id' => $category->id]]]);
            $this->fail('Expected archived ledger validation exception.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('bookkeeping_entries', 0);
            $this->assertDatabaseCount('mcp_operations', 0);
        }
    }

    public function test_archived_ledger_cannot_be_updated_or_archived_again(): void
    {
        $this->context();
        $this->ledger->update(['status' => 'archived']);

        foreach ([
            fn (): Ledger => app(LedgerService::class)->update($this->ledger, ['name' => '已归档账本']),
            fn (): Ledger => app(LedgerService::class)->archive($this->ledger),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected archived ledger validation exception.');
            } catch (ValidationException) {
                $this->ledger->refresh();
                $this->assertSame('archived', $this->ledger->status);
                $this->assertSame(1, $this->ledger->revision);
            }
        }
    }

    public function test_account_balances_are_aggregated_in_one_decimal_query(): void
    {
        $context = $this->context();
        $accounts = app(BookkeepingAccountService::class);
        $source = $accounts->defaultAccount($this->ledger);
        $destination = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '10.00', 'category_id' => $category->id, 'account_id' => $source->id], 'admin');
        app(BookkeepingTransferService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'source_account_id' => $source->id, 'destination_account_id' => $destination->id, 'amount' => '2.50']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $balances = $accounts->accountsWithBalances($this->ledger)->orderBy('id')->get()->mapWithKeys(fn (Account $account): array => [$account->id => $account->balance]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame('7.50', $balances[$source->id]);
        $this->assertSame('2.50', $balances[$destination->id]);
    }

    public function test_account_balance_includes_the_opening_balance_without_affecting_income_or_expense(): void
    {
        $context = $this->context();
        $account = app(BookkeepingAccountService::class)->create($context, $this->ledger, [
            'name' => '现金',
            'type' => 'cash',
            'opening_balance' => '100.00',
            'is_active' => true,
        ]);
        $income = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        app(BookkeepingService::class)->createEntry($context->user, $this->ledger, [
            'direction' => 'income',
            'amount' => '25.00',
            'category_id' => $income->id,
            'account_id' => $account->id,
        ], 'admin');

        $balance = app(BookkeepingAccountService::class)->accountsWithBalances($this->ledger)->whereKey($account->id)->value('balance');
        $summary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id]);

        $this->assertSame('125.00', (string) $balance);
        $this->assertSame('25.00', $summary['totals']['income']);
        $this->assertSame('0.00', $summary['totals']['expense']);
    }

    public function test_account_balance_aggregate_preserves_values_beyond_the_source_decimal_range(): void
    {
        $context = $this->context();
        $account = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        $service = app(BookkeepingService::class);

        for ($index = 0; $index < 101; $index++) {
            $service->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '9999999999.99', 'category_id' => $category->id, 'account_id' => $account->id], 'admin');
        }

        $balance = app(BookkeepingAccountService::class)->accountsWithBalances($this->ledger)->whereKey($account->id)->value('balance');

        $this->assertSame('1009999999998.99', (string) $balance);
    }

    public function test_dashboard_uses_the_selected_range_excludes_voided_entries_and_keeps_transfers_out_of_income_and_expense(): void
    {
        $context = $this->context();
        $accounts = app(BookkeepingAccountService::class);
        $source = $accounts->defaultAccount($this->ledger);
        $destination = Account::factory()->create(['ledger_id' => $this->ledger->id, 'name' => '现金']);
        $income = Category::factory()->create(['ledger_id' => $this->ledger->id, 'name' => '营业收入', 'direction' => 'income']);
        $expense = Category::factory()->create(['ledger_id' => $this->ledger->id, 'name' => '食材', 'direction' => 'expense']);
        $service = app(BookkeepingService::class);
        $service->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '100.00', 'category_id' => $income->id, 'occurred_on' => '2026-10-01', 'account_id' => $source->id], 'admin');
        $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '25.00', 'category_id' => $expense->id, 'occurred_on' => '2026-10-03', 'account_id' => $source->id], 'admin');
        $voided = $service->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '40.00', 'category_id' => $expense->id, 'occurred_on' => '2026-10-02', 'account_id' => $source->id], 'admin');
        $service->void($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $voided->public_id, 'revision' => 1]);
        app(BookkeepingTransferService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'source_account_id' => $source->id, 'destination_account_id' => $destination->id, 'amount' => '12.50', 'occurred_on' => '2026-10-03']);

        $dashboard = app(BookkeepingQueryService::class)->dashboard($context, ['ledger_id' => $this->ledger->public_id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-03']);

        $this->assertSame(['2026-10-01', '2026-10-02', '2026-10-03'], $dashboard['trend']['labels']);
        $this->assertSame(['100.00', '0.00', '0.00'], $dashboard['trend']['datasets'][0]['data']);
        $this->assertSame(['0.00', '0.00', '25.00'], $dashboard['trend']['datasets'][1]['data']);
        $this->assertSame('100.00', $dashboard['totals']['income']);
        $this->assertSame('25.00', $dashboard['totals']['expense']);
        $this->assertSame('75.00', $dashboard['totals']['balance']);
        $this->assertSame('75.00', $dashboard['account_total']);
        $this->assertSame('62.50', collect($dashboard['accounts'])->firstWhere('id', $source->id)['balance']);
        $this->assertSame('12.50', collect($dashboard['accounts'])->firstWhere('id', $destination->id)['balance']);
        $this->assertCount(2, $dashboard['recent']);
    }

    public function test_dashboard_rejects_reversed_and_oversized_date_ranges(): void
    {
        $context = $this->context();

        try {
            app(BookkeepingQueryService::class)->dashboard($context, ['ledger_id' => $this->ledger->public_id, 'start_date' => '2026-10-16', 'end_date' => '2026-10-15']);
            $this->fail('Expected a reversed date range validation exception.');
        } catch (ValidationException $exception) {
            $this->assertSame(['开始日期不得晚于结束日期。'], $exception->errors()['start_date']);
        }

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('日期范围最多为 1096 天。');
        app(BookkeepingQueryService::class)->dashboard($context, ['ledger_id' => $this->ledger->public_id, 'start_date' => '2023-10-15', 'end_date' => '2026-10-16']);
    }

    public function test_reconciliation_schema_migration_does_not_retire_legacy_cash_rows(): void
    {
        $this->createLegacyCashTable();
        DB::table('zdp_cash_transactions')->insert(['id' => 1]);

        try {
            $migration = require base_path('database/migrations/2026_10_15_094248_retire_zhendianpu_business_tables.php');
            $migration->up();
            $this->assertTrue(Schema::hasTable('zdp_cash_transactions'));
            $this->assertTrue(Schema::hasTable('bookkeeping_legacy_cash_reconciliations'));
        } finally {
            Schema::dropIfExists('zdp_cash_transactions');
        }
    }

    public function test_legacy_cash_import_is_repeatable_and_reconciles_all_source_types(): void
    {
        $context = $this->context();
        $this->createLegacyCashTable();
        DB::table('zdp_cash_transactions')->insert([
            ['id' => 1, 'business_on' => '2026-10-01', 'direction' => 'out', 'amount' => '5.00', 'source_type' => 'purchase', 'source_business_id' => 'purchase-1', 'voided_at' => null],
            ['id' => 2, 'business_on' => '2026-10-02', 'direction' => 'in', 'amount' => '6.00', 'source_type' => 'sale', 'source_business_id' => 'sale-1', 'voided_at' => null],
            ['id' => 3, 'business_on' => '2026-10-03', 'direction' => 'out', 'amount' => '7.00', 'source_type' => null, 'source_business_id' => null, 'voided_at' => null],
            ['id' => 4, 'business_on' => '2026-10-04', 'direction' => 'out', 'amount' => '8.00', 'source_type' => 'purchase', 'source_business_id' => 'purchase-void', 'voided_at' => now()],
            ['id' => 5, 'business_on' => '2026-10-05', 'direction' => 'transfer', 'amount' => '9.00', 'source_type' => null, 'source_business_id' => null, 'voided_at' => null],
        ]);

        $this->artisan('app:import-legacy-cash-entries', ['--actor-id' => $context->user->id])->assertSuccessful();
        $this->artisan('app:import-legacy-cash-entries', ['--actor-id' => $context->user->id])->assertSuccessful();

        $this->assertDatabaseCount('bookkeeping_entries', 3);
        $this->assertDatabaseCount('bookkeeping_legacy_imports', 3);
        $this->assertDatabaseCount('bookkeeping_legacy_cash_reconciliations', 5);
        $this->assertDatabaseHas('bookkeeping_legacy_cash_reconciliations', ['source_id' => 4, 'status' => 'excluded', 'exclusion_reason' => 'legacy_row_voided']);
        $this->assertDatabaseHas('bookkeeping_legacy_cash_reconciliations', ['source_id' => 5, 'status' => 'excluded', 'exclusion_reason' => 'invalid_direction_or_amount']);
    }

    public function test_retirement_migration_rejects_missing_duplicate_and_changed_reconciliations(): void
    {
        $this->createLegacyCashTable();
        DB::table('zdp_cash_transactions')->insert(['id' => 1]);

        $migration = require base_path('database/migrations/2026_10_15_094250_retire_reconciled_zhendianpu_business_tables.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reconcile and confirm every legacy cash row before retiring zhendianpu tables.');
        $migration->up();
    }

    public function test_retirement_migration_drops_empty_legacy_business_tables(): void
    {
        $this->createLegacyBusinessTable('zdp_categories');

        $migration = require base_path('database/migrations/2026_10_15_094250_retire_reconciled_zhendianpu_business_tables.php');
        $migration->up();

        $this->assertFalse(Schema::hasTable('zdp_categories'));
    }

    public function test_retirement_migration_preserves_non_cash_legacy_data_without_confirmed_backup(): void
    {
        $this->createLegacyBusinessTable('zdp_categories');
        DB::table('zdp_categories')->insert(['id' => 1, 'name' => '采购分类']);

        $migration = require base_path('database/migrations/2026_10_15_094250_retire_reconciled_zhendianpu_business_tables.php');

        try {
            $migration->up();
            $this->fail('Expected non-cash legacy data to block retirement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Confirm the row count, backup, and retirement of every non-empty legacy table before dropping zdp_categories.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('zdp_categories'));
        $this->assertDatabaseHas('zdp_categories', ['id' => 1, 'name' => '采购分类']);
    }

    public function test_retirement_migration_drops_confirmed_non_cash_legacy_data_after_the_reconciliation_schema_has_already_run(): void
    {
        $reconciliationMigration = require base_path('database/migrations/2026_10_15_094248_retire_zhendianpu_business_tables.php');
        $reconciliationMigration->up();
        $this->createLegacyBusinessTable('zdp_categories');
        DB::table('zdp_categories')->insert(['id' => 1, 'name' => '销售分类']);
        $this->artisan('app:confirm-legacy-table-retirement', [
            'table' => 'zdp_categories',
            '--actor-id' => User::factory()->create()->id,
            '--backup-reference' => 'immutable://backup',
        ])->assertSuccessful();

        $migration = require base_path('database/migrations/2026_10_15_094250_retire_reconciled_zhendianpu_business_tables.php');
        $migration->up();

        $this->assertFalse(Schema::hasTable('zdp_categories'));
    }

    public function test_retirement_migration_rejects_non_cash_rows_changed_after_snapshot_confirmation(): void
    {
        $this->createLegacyBusinessTable('zdp_categories');
        DB::table('zdp_categories')->insert(['id' => 1, 'name' => '采购分类']);
        $actor = User::factory()->create();
        $this->artisan('app:confirm-legacy-table-retirement', [
            'table' => 'zdp_categories',
            '--actor-id' => $actor->id,
            '--backup-reference' => 'immutable://backup',
        ])->assertSuccessful();
        DB::table('zdp_categories')->where('id', 1)->update(['name' => '销售分类']);

        $migration = require base_path('database/migrations/2026_10_15_094250_retire_reconciled_zhendianpu_business_tables.php');

        try {
            $migration->up();
            $this->fail('Expected changed non-cash legacy data to block retirement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Confirm an immutable content snapshot for zdp_categories before dropping it.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('zdp_categories'));
        $this->assertDatabaseHas('zdp_categories', ['id' => 1, 'name' => '销售分类']);
    }

    public function test_retirement_migration_rejects_duplicate_mappings_and_changed_source_rows(): void
    {
        $this->createLegacyCashTable();
        $source = ['id' => 1];
        DB::table('zdp_cash_transactions')->insert($source);
        $this->confirmRetirement(1);
        $snapshot = LegacyCashSnapshot::capture(DB::table('zdp_cash_transactions')->where('id', 1)->first());
        $this->insertExcludedReconciliation(1, $snapshot);
        $uniqueIndexDropped = false;

        try {
            Schema::table('bookkeeping_legacy_cash_reconciliations', function (Blueprint $table): void {
                $table->dropUnique('legacy_cash_source_unique');
            });
            $uniqueIndexDropped = true;
            $this->insertExcludedReconciliation(1, $snapshot);

            $migration = require base_path('database/migrations/2026_10_15_094250_retire_reconciled_zhendianpu_business_tables.php');

            $migration->up();
            $this->fail('Expected duplicate source mappings to block retirement.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Legacy cash reconciliation contains duplicate source mappings.', $exception->getMessage());

            DB::table('bookkeeping_legacy_cash_reconciliations')->where('id', '>', 1)->delete();
            DB::table('zdp_cash_transactions')->where('id', 1)->update(['notes' => 'changed after reconciliation']);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Legacy cash reconciliation no longer matches its immutable source row.');
            $migration->up();
        } finally {
            DB::table('bookkeeping_legacy_cash_reconciliations')->where('source_table', 'zdp_cash_transactions')->delete();
            if ($uniqueIndexDropped) {
                Schema::table('bookkeeping_legacy_cash_reconciliations', function (Blueprint $table): void {
                    $table->unique(['source_table', 'source_id'], 'legacy_cash_source_unique');
                });
            }
        }
    }

    public function test_retirement_migration_rejects_imported_entry_with_changed_financial_values(): void
    {
        $context = $this->context();
        $this->createLegacyCashTable();
        $source = ['id' => 1, 'business_on' => '2026-10-01', 'direction' => 'out', 'amount' => '10.00'];
        DB::table('zdp_cash_transactions')->insert($source);
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '9.99', 'category_id' => $category->id, 'occurred_on' => '2026-10-01'], 'legacy_import');
        $snapshot = LegacyCashSnapshot::capture(DB::table('zdp_cash_transactions')->where('id', 1)->first());
        $this->confirmRetirement(1);
        DB::table('bookkeeping_legacy_imports')->insert(['source_table' => 'zdp_cash_transactions', 'source_id' => 1, 'entry_id' => $entry->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bookkeeping_legacy_cash_reconciliations')->insert(['source_table' => 'zdp_cash_transactions', 'source_id' => 1, 'status' => 'imported', 'entry_id' => $entry->id, 'source_snapshot' => $snapshot['snapshot'], 'source_hash' => $snapshot['hash'], 'reconciled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $migration = require base_path('database/migrations/2026_10_15_094250_retire_reconciled_zhendianpu_business_tables.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Imported legacy cash rows must preserve direction, amount, and date.');
        $migration->up();
    }

    public function test_refunds_adjust_account_balances_net_summaries_and_protect_original_entries(): void
    {
        $context = $this->context();
        $income = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        $expense = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $source = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $refundAccount = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $entries = app(BookkeepingService::class);
        $incomeEntry = $entries->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '100.00', 'category_id' => $income->id, 'account_id' => $source->id, 'occurred_on' => '2026-10-01'], 'admin');
        $expenseEntry = $entries->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '80.00', 'category_id' => $expense->id, 'account_id' => $source->id, 'occurred_on' => '2026-10-01'], 'admin');
        $refunds = app(BookkeepingRefundService::class);
        $refunds->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $incomeEntry->public_id, 'account_id' => $refundAccount->id, 'amount' => '20.00', 'occurred_on' => '2026-10-02']);
        $expenseRefund = $refunds->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $expenseEntry->public_id, 'account_id' => $refundAccount->id, 'amount' => '30.00', 'occurred_on' => '2026-10-02']);
        $summary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-02']);
        $balances = app(BookkeepingAccountService::class)->accountsWithBalances($this->ledger)->pluck('balance', 'id');
        $this->assertSame('80.00', $summary['totals']['income']);
        $this->assertSame('50.00', $summary['totals']['expense']);
        $this->assertSame('20.00', $summary['totals']['income_refunds']);
        $this->assertSame('30.00', $summary['totals']['expense_refunds']);
        $this->assertSame('10.00', (string) $balances[$refundAccount->id]);
        $this->expectException(ValidationException::class);
        $refunds->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $expenseEntry->public_id, 'account_id' => $refundAccount->id, 'amount' => '60.00']);
    }

    public function test_entries_with_active_refunds_cannot_change_direction_or_category_and_keep_the_refund_amount_floor(): void
    {
        $context = $this->context();
        $income = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        $replacementIncome = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        $expense = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $account = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '100.00', 'category_id' => $income->id, 'account_id' => $account->id], 'admin');
        app(BookkeepingRefundService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'account_id' => $account->id, 'amount' => '20.00']);

        foreach ([
            ['direction' => 'expense', 'category_id' => $expense->id],
            ['category_id' => $replacementIncome->id],
            ['amount' => '19.99'],
        ] as $changes) {
            try {
                app(BookkeepingService::class)->update($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'revision' => 1, ...$changes]);
                $this->fail('Expected active refunds to protect the original entry.');
            } catch (ValidationException) {
                $entry->refresh();
                $this->assertSame('income', $entry->direction);
                $this->assertSame($income->id, $entry->category_id);
                $this->assertSame('100.00', $entry->amount);
                $this->assertSame(1, $entry->revision);
            }
        }
    }

    public function test_refund_reports_filter_by_the_refund_account_not_the_original_entry_account(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $originalAccount = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $refundAccount = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '100.00', 'category_id' => $category->id, 'account_id' => $originalAccount->id, 'occurred_on' => '2026-10-01'], 'admin');
        app(BookkeepingRefundService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'account_id' => $refundAccount->id, 'amount' => '20.00', 'occurred_on' => '2026-10-02']);

        $originalAccountSummary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'account_id' => $originalAccount->id]);
        $refundAccountSummary = app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'account_id' => $refundAccount->id]);

        $this->assertSame(['income' => '0.00', 'expense' => '100.00', 'balance' => '-100.00'], $originalAccountSummary['totals']);
        $this->assertSame('20.00', $refundAccountSummary['totals']['expense_refunds']);
        $this->assertSame('-20.00', $refundAccountSummary['totals']['expense']);
    }

    public function test_dashboard_includes_inactive_accounts_referenced_only_by_refunds(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'income']);
        $entryAccount = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $refundAccount = Account::factory()->create(['ledger_id' => $this->ledger->id]);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'income', 'amount' => '100.00', 'category_id' => $category->id, 'account_id' => $entryAccount->id], 'admin');
        app(BookkeepingRefundService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'account_id' => $refundAccount->id, 'amount' => '20.00']);
        $refundAccount->update(['is_active' => false]);

        $dashboard = app(BookkeepingQueryService::class)->dashboard($context, ['ledger_id' => $this->ledger->public_id]);

        $this->assertSame('-20.00', collect($dashboard['accounts'])->firstWhere('id', $refundAccount->id)['balance']);
        $this->assertSame('80.00', $dashboard['account_total']);
    }

    public function test_refunds_are_ledger_scoped_voidable_and_registered(): void
    {
        $context = $this->context();
        $category = Category::factory()->create(['ledger_id' => $this->ledger->id, 'direction' => 'expense']);
        $entry = app(BookkeepingService::class)->createEntry($context->user, $this->ledger, ['direction' => 'expense', 'amount' => '10.00', 'category_id' => $category->id], 'admin');
        $account = app(BookkeepingAccountService::class)->defaultAccount($this->ledger);
        $created = app(BookkeepingRefundService::class)->add($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $entry->public_id, 'account_id' => $account->id, 'amount' => '5.00']);
        app(BookkeepingRefundService::class)->void($context, ['ledger_id' => $this->ledger->public_id, 'event_id' => (string) Str::ulid(), 'refund_id' => $created['refund']['id'], 'revision' => 1]);
        $this->assertSame('10.00', app(BookkeepingQueryService::class)->summary($context, ['ledger_id' => $this->ledger->public_id])['totals']['expense']);
        ZhendianpuServer::tools()->assertRegistered([AddRefund::class, ListRefunds::class, UpdateRefund::class, VoidRefund::class]);
    }

    private function createLegacyCashTable(): void
    {
        Schema::create('zdp_cash_transactions', function (Blueprint $table): void {
            $table->id();
            $table->date('business_on')->nullable();
            $table->string('direction')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_business_id', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
        });
    }

    private function createLegacyBusinessTable(string $table): void
    {
        Schema::create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });
    }

    private function confirmRetirement(int $rowCount, string $table = 'zdp_cash_transactions'): void
    {
        $retirement = ['source_table' => $table, 'row_count' => $rowCount, 'backup_reference' => 'immutable://backup', 'confirmed_by' => User::factory()->create()->id, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()];
        if ($table !== 'zdp_cash_transactions') {
            $source = LegacyTableSnapshot::capture($table);
            $retirement['source_snapshot'] = $source['snapshot'];
            $retirement['source_hash'] = $source['hash'];
        }

        DB::table('bookkeeping_legacy_retirements')->insert($retirement);
    }

    /** @param array{snapshot: string, hash: string} $source */
    private function insertExcludedReconciliation(int $sourceId, array $source): void
    {
        DB::table('bookkeeping_legacy_cash_reconciliations')->insert(['source_table' => 'zdp_cash_transactions', 'source_id' => $sourceId, 'status' => 'excluded', 'exclusion_reason' => 'legacy_row_voided', 'source_snapshot' => $source['snapshot'], 'source_hash' => $source['hash'], 'reconciled_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
