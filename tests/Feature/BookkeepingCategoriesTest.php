<?php

namespace Tests\Feature;

use App\Filament\Pages\BookkeepingCategories;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Ledger;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bookkeeping\LedgerService;
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
