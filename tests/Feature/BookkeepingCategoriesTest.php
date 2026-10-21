<?php

namespace Tests\Feature;

use App\Filament\Pages\BookkeepingCategories;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Ledger;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bookkeeping\LedgerService;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BookkeepingCategoriesTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'users',
        'workspaces',
        'workspace_members',
        'mcp_servers',
        'mcp_server_access',
        'bookkeeping_ledgers',
        'bookkeeping_categories',
        'bookkeeping_accounts',
        'bookkeeping_entries',
        'bookkeeping_transfers',
    ];

    public function test_category_table_shows_current_ledger_top_level_categories_and_selected_children(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $expenseParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '食材采购', 'direction' => 'expense']);
        $expenseChild = Category::factory()->create(['ledger_id' => $ledger->id, 'parent_id' => $expenseParent->id, 'name' => '面粉', 'direction' => 'expense']);
        $incomeParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '销售', 'direction' => 'income']);
        $otherLedgerParent = Category::factory()->create(['name' => '其他账本支出', 'direction' => 'expense']);

        Livewire::actingAs($user)
            ->test(BookkeepingCategories::class)
            ->assertCanSeeTableRecords([$expenseParent])
            ->assertCanNotSeeTableRecords([$expenseChild, $incomeParent, $otherLedgerParent])
            ->call('showSubcategories', $expenseParent)
            ->assertSet('selectedTopLevelCategoryId', $expenseParent->id)
            ->assertCanSeeTableRecords([$expenseChild])
            ->assertCanNotSeeTableRecords([$expenseParent, $incomeParent, $otherLedgerParent]);
    }

    public function test_invalid_selection_falls_back_to_top_level_categories_and_direction_switch_clears_selection(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $expenseParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '食材采购', 'direction' => 'expense']);
        $incomeParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '销售', 'direction' => 'income']);
        $otherLedgerParent = Category::factory()->create(['name' => '其他账本支出', 'direction' => 'expense']);

        Livewire::actingAs($user)
            ->test(BookkeepingCategories::class)
            ->set('selectedTopLevelCategoryId', $otherLedgerParent->id)
            ->assertSet('selectedTopLevelCategoryId', null)
            ->assertCanSeeTableRecords([$expenseParent])
            ->assertCanNotSeeTableRecords([$otherLedgerParent])
            ->call('showSubcategories', $expenseParent)
            ->set('activeDirection', 'income')
            ->assertSet('selectedTopLevelCategoryId', null)
            ->assertCanSeeTableRecords([$incomeParent])
            ->assertCanNotSeeTableRecords([$expenseParent]);
    }

    public function test_create_action_defaults_to_the_active_direction_and_selected_parent(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $expenseParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '食材采购', 'direction' => 'expense']);

        Livewire::actingAs($user)
            ->test(BookkeepingCategories::class)
            ->mountTableAction('addCategory')
            ->assertTableActionDataSet([
                'direction' => 'expense',
                'parent_id' => null,
                'sort_order' => 0,
            ])
            ->unmountTableAction()
            ->set('activeDirection', 'income')
            ->mountTableAction('addCategory')
            ->assertTableActionDataSet([
                'direction' => 'income',
                'parent_id' => null,
                'sort_order' => 0,
            ])
            ->unmountTableAction()
            ->set('activeDirection', 'expense')
            ->call('showSubcategories', $expenseParent)
            ->mountTableAction('addCategory')
            ->assertTableActionDataSet([
                'direction' => 'expense',
                'parent_id' => $expenseParent->id,
                'sort_order' => 0,
            ]);
    }

    public function test_parent_options_follow_the_form_direction_and_clear_the_selected_parent_when_it_changes(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $expenseParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '食材采购', 'direction' => 'expense']);
        $incomeParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '堂食销售', 'direction' => 'income']);
        Category::factory()->create(['name' => '其他账本食材', 'direction' => 'expense']);

        Livewire::actingAs($user)
            ->test(BookkeepingCategories::class)
            ->call('showSubcategories', $expenseParent)
            ->mountTableAction('addCategory')
            ->assertSchemaComponentExists('parent_id', checkComponentUsing: fn (Select $component): bool => $component->getOptions() === [$expenseParent->id => $expenseParent->displayName()])
            ->setActionData(['direction' => 'income'])
            ->assertTableActionDataSet(['direction' => 'income', 'parent_id' => null])
            ->assertSchemaComponentExists('parent_id', checkComponentUsing: fn (Select $component): bool => $component->getOptions() === [$incomeParent->id => $incomeParent->displayName()]);
    }

    public function test_edit_action_retains_record_data_and_excludes_the_record_from_parent_options(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $category = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '食材采购', 'direction' => 'expense', 'sort_order' => 3]);
        $child = Category::factory()->create(['ledger_id' => $ledger->id, 'parent_id' => $category->id, 'name' => '面粉', 'direction' => 'expense']);
        $eligibleParent = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '包装耗材', 'direction' => 'expense']);
        Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '堂食销售', 'direction' => 'income']);
        Category::factory()->create(['name' => '其他账本食材', 'direction' => 'expense']);

        Livewire::actingAs($user)
            ->test(BookkeepingCategories::class)
            ->mountTableAction('editCategory', $category)
            ->assertTableActionDataSet([
                'name' => '食材采购',
                'direction' => 'expense',
                'parent_id' => null,
                'sort_order' => 3,
            ])
            ->assertSchemaComponentExists('parent_id', checkComponentUsing: fn (Select $component): bool => $component->getOptions() === [$eligibleParent->id => $eligibleParent->displayName()])
            ->unmountTableAction()
            ->call('showSubcategories', $category)
            ->mountTableAction('editCategory', $child)
            ->assertTableActionDataSet(['parent_id' => $category->id]);
    }

    /** @return array{User, Ledger} */
    private function authorizedUserAndCurrentLedger(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $server = McpServer::factory()->create(['module_key' => 'zhendianpu']);
        $ledger = Ledger::factory()->create();
        $membership = ['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'writer', 'created_at' => now(), 'updated_at' => now()];

        DB::table('workspace_members')->insert($membership);
        DB::table('mcp_server_access')->insert([...$membership, 'server_id' => $server->id]);
        app(LedgerService::class)->selectCurrent($user, $ledger);

        return [$user, $ledger];
    }
}
