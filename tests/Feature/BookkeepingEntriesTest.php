<?php

namespace Tests\Feature;

use App\Filament\Pages\BookkeepingEntries;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Refund;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bookkeeping\LedgerService;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BookkeepingEntriesTest extends TestCase
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
        'bookkeeping_refunds',
        'bookkeeping_transfers',
    ];

    public function test_category_filter_scopes_and_groups_options_and_filters_current_ledger_entries(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $topLevelCategory = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '食材采购']);
        $childCategory = Category::factory()->create(['ledger_id' => $ledger->id, 'parent_id' => $topLevelCategory->id, 'name' => '面粉']);
        $otherLedgerCategory = Category::factory()->create(['name' => '其他账本分类']);
        $topLevelEntry = Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $topLevelCategory->id]);
        $childEntry = Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $childCategory->id]);
        $otherLedgerEntry = Entry::factory()->create(['category_id' => $otherLedgerCategory->id]);

        Livewire::actingAs($user)
            ->test(BookkeepingEntries::class)
            ->assertTableFilterExists('category_id', function (SelectFilter $filter) use ($topLevelCategory, $childCategory): bool {
                return $filter->getOptions() === [
                    '一级分类' => [$topLevelCategory->id => '食材采购'],
                    '二级分类' => [$childCategory->id => '食材采购 / 面粉'],
                ];
            })
            ->assertCanSeeTableRecords([$topLevelEntry, $childEntry])
            ->assertCanNotSeeTableRecords([$otherLedgerEntry])
            ->filterTable('category_id', $childCategory->id)
            ->assertCanSeeTableRecords([$childEntry])
            ->assertCanNotSeeTableRecords([$topLevelEntry, $otherLedgerEntry]);
    }

    public function test_active_refunds_have_edit_and_void_actions_in_the_current_ledger(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $category = Category::factory()->create(['ledger_id' => $ledger->id]);
        $entry = Entry::factory()->create(['ledger_id' => $ledger->id, 'account_id' => $account->id, 'category_id' => $category->id]);
        Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $entry->id, 'account_id' => $account->id]);

        Livewire::actingAs($user)
            ->test(BookkeepingEntries::class)
            ->assertTableActionVisible('editRefund', $entry)
            ->assertTableActionVisible('voidRefund', $entry);
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
