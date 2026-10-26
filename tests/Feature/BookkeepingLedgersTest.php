<?php

namespace Tests\Feature;

use App\Filament\Pages\BookkeepingLedgers;
use App\Models\Bookkeeping\Ledger;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Bookkeeping\LedgerService;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BookkeepingLedgersTest extends TestCase
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
    ];

    public function test_monthly_report_column_shows_available_reports_and_missing_report_statuses(): void
    {
        Storage::fake('local');
        [$user, $currentLedger] = $this->authorizedUserAndCurrentLedger();
        $availableLedger = Ledger::factory()->create();
        $missingLedger = Ledger::factory()->create();
        Storage::disk('local')->put('reports/monthly/'.$availableLedger->public_id.'/latest.html', '<!doctype html>');

        Livewire::actingAs($user)
            ->test(BookkeepingLedgers::class)
            ->assertTableColumnVisible('monthly_report')
            ->assertTableColumnStateSet('monthly_report', '查看月报', $availableLedger)
            ->assertTableColumnStateSet('monthly_report', '尚未生成', $missingLedger)
            ->assertTableColumnExists('monthly_report', fn (TextColumn $column): bool => $column->getUrl() === route('reports.monthly.show', $availableLedger) && $column->shouldOpenUrlInNewTab(), $availableLedger)
            ->assertTableColumnExists('monthly_report', fn (TextColumn $column): bool => $column->getUrl() === null, $missingLedger)
            ->assertTableActionDoesNotExist('viewMonthlyReport', record: $availableLedger)
            ->assertTableActionDoesNotExist('archiveLedger', record: $missingLedger);
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
