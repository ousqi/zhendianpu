<?php

namespace Tests\Feature;

use App\Filament\Pages\BookkeepingEntries;
use App\Filament\Pages\BookkeepingRefunds;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Refund;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bookkeeping\LedgerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BookkeepingRefundsTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'users', 'workspaces', 'workspace_members', 'mcp_servers', 'mcp_server_access',
        'bookkeeping_ledgers', 'bookkeeping_categories', 'bookkeeping_accounts', 'bookkeeping_entries', 'bookkeeping_refunds',
    ];

    public function test_refund_records_are_scoped_to_the_current_ledger_and_include_voided_records_when_status_is_all(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $category = Category::factory()->create(['ledger_id' => $ledger->id]);
        $entry = Entry::factory()->create(['ledger_id' => $ledger->id, 'account_id' => $account->id, 'category_id' => $category->id]);
        $validRefund = Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $entry->id, 'account_id' => $account->id]);
        $voidedRefund = Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $entry->id, 'account_id' => $account->id, 'voided_at' => now()]);
        $otherLedgerRefund = Refund::factory()->create();

        Livewire::actingAs($user)
            ->test(BookkeepingRefunds::class)
            ->filterTable('voided', 'all')
            ->assertCanSeeTableRecords([$validRefund, $voidedRefund])
            ->assertCanNotSeeTableRecords([$otherLedgerRefund]);
    }

    public function test_refund_management_actions_are_on_refund_records(): void
    {
        [$user, $ledger] = $this->authorizedUserAndCurrentLedger();
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $category = Category::factory()->create(['ledger_id' => $ledger->id]);
        $entry = Entry::factory()->create(['ledger_id' => $ledger->id, 'account_id' => $account->id, 'category_id' => $category->id]);
        $refund = Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $entry->id, 'account_id' => $account->id]);

        Livewire::actingAs($user)
            ->test(BookkeepingEntries::class)
            ->assertTableActionDoesNotExist('viewRefundHistory', record: $entry)
            ->assertTableActionDoesNotExist('editRefund', record: $entry)
            ->assertTableActionDoesNotExist('voidRefund', record: $entry);

        Livewire::actingAs($user)
            ->test(BookkeepingRefunds::class)
            ->assertTableActionVisible('editRefund', $refund)
            ->assertTableActionVisible('voidRefund', $refund);

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
