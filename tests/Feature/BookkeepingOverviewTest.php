<?php

namespace Tests\Feature;

use App\Filament\Pages\BookkeepingOverview;
use App\Filament\Widgets\BookkeepingAccountBalancesChart;
use App\Filament\Widgets\BookkeepingExpenseCategoriesChart;
use App\Filament\Widgets\BookkeepingIncomeCategoriesChart;
use App\Filament\Widgets\BookkeepingTrendChart;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BookkeepingOverviewTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'users',
        'workspaces',
        'workspace_members',
        'mcp_servers',
        'mcp_server_access',
        'bookkeeping_categories',
        'bookkeeping_accounts',
        'bookkeeping_entries',
        'bookkeeping_refunds',
        'bookkeeping_transfers',
    ];

    public function test_chart_widgets_render_with_filament_five_option_signatures(): void
    {
        Livewire::test(BookkeepingTrendChart::class, ['chartData' => [
            'labels' => ['2026-10-01'],
            'datasets' => [
                ['label' => '收入', 'data' => ['100.00']],
                ['label' => '支出', 'data' => ['50.00']],
            ],
        ]])->assertOk()->assertSee('收支趋势')->assertSee('收入 100.00 元');

        Livewire::test(BookkeepingExpenseCategoriesChart::class, ['chartData' => [
            'labels' => ['餐饮'],
            'percentages' => ['100.00'],
            'datasets' => [['label' => '支出', 'data' => ['50.00']]],
        ]])->assertOk()->assertSee('支出分类')->assertSee('餐饮：50.00 元，100.00%');

        Livewire::test(BookkeepingIncomeCategoriesChart::class, ['chartData' => [
            'labels' => ['销售'],
            'datasets' => [['label' => '收入', 'data' => ['100.00']]],
        ]])->assertOk()->assertSee('收入分类')->assertSee('销售：100.00 元');

        Livewire::test(BookkeepingAccountBalancesChart::class, ['chartData' => [
            'labels' => ['现金'],
            'datasets' => [['label' => '当前余额', 'data' => ['50.00']]],
        ]])->assertOk()->assertSee('账户余额')->assertSee('现金：50.00 元');
    }

    public function test_overview_rejects_reversed_future_and_oversized_date_ranges(): void
    {
        $overview = app(BookkeepingOverview::class);
        $today = now('Asia/Shanghai')->toDateString();

        $overview->startDate = $today;
        $overview->endDate = Carbon::parse($today, 'Asia/Shanghai')->subDay()->toDateString();
        $this->assertFalse($overview->hasValidDateRange());

        $overview->startDate = Carbon::parse($today, 'Asia/Shanghai')->subDays(1096)->toDateString();
        $overview->endDate = $today;
        $this->assertFalse($overview->hasValidDateRange());

        $overview->startDate = $today;
        $overview->endDate = Carbon::parse($today, 'Asia/Shanghai')->addDay()->toDateString();
        $this->assertFalse($overview->hasValidDateRange());
    }

    public function test_current_range_presets_end_on_today(): void
    {
        $overview = app(BookkeepingOverview::class);
        $today = now('Asia/Shanghai')->toDateString();

        foreach (['week', 'month', 'year'] as $range) {
            $overview->useRange($range);

            $this->assertSame($today, $overview->endDate);
            $this->assertTrue($overview->hasValidDateRange());
        }
    }

    public function test_overview_defaults_to_the_current_month_through_today(): void
    {
        $overview = app(BookkeepingOverview::class);
        $today = now('Asia/Shanghai');

        $overview->mount();

        $this->assertSame($today->copy()->startOfMonth()->toDateString(), $overview->startDate);
        $this->assertSame($today->toDateString(), $overview->endDate);
        $this->assertTrue($overview->hasValidDateRange());
    }

    public function test_overview_page_renders_bounded_date_controls(): void
    {
        Livewire::test(RenderableBookkeepingOverview::class)
            ->assertOk()
            ->assertSee('最多 1096 天')
            ->assertSee('当前值：')
            ->assertSee('（yyyy-mm-dd）')
            ->assertSee('aria-pressed="true"', false);
    }

    public function test_overview_renders_full_empty_layout_without_inventing_chart_values(): void
    {
        Livewire::test(EmptyRenderableBookkeepingOverview::class)
            ->call('useRange', 'month')
            ->assertOk()
            ->assertSee('¥ 0.00')
            ->assertSee('¥ 125.00')
            ->assertSee('这个日期范围还没有收支记录')
            ->assertSee('本范围收入、支出与期间结余为 0.00；账户总余额显示当前账户余额，可能不为 0.00。')
            ->assertSee('前往明细记一笔')
            ->assertSee('设置账户')
            ->assertSee('<table', false)
            ->assertSee('当前期间暂无记录')
            ->assertDontSee('收入 100.00 元');
    }

    public function test_empty_overview_passes_visible_zero_baseline_and_category_placeholders_to_chart_widgets(): void
    {
        $overview = app(EmptyRenderableBookkeepingOverview::class);

        $chartData = $overview->chartData($overview->overview());

        $this->assertSame(['2026-10-01', '2026-10-02'], $chartData['trend']['labels']);
        $this->assertSame(['0.00', '0.00'], $chartData['trend']['datasets'][0]['data']);
        $this->assertSame(['0.00', '0.00'], $chartData['trend']['datasets'][1]['data']);
        $this->assertTrue($chartData['expense']['placeholder']);
        $this->assertTrue($chartData['income']['placeholder']);
        $this->assertSame([], $chartData['accounts']);
    }

    public function test_read_only_overview_shows_viewing_guidance_without_write_ctas(): void
    {
        Livewire::test(ReadOnlyEmptyRenderableBookkeepingOverview::class)
            ->call('useRange', 'month')
            ->assertOk()
            ->assertSee('你可以查看当前范围的收支明细和当前账户余额。')
            ->assertDontSee('前往明细记一笔')
            ->assertDontSee('设置账户');
    }

    public function test_overview_keeps_zero_balance_accounts_visible(): void
    {
        Livewire::test(ZeroBalanceAccountsRenderableBookkeepingOverview::class)
            ->call('useRange', 'month')
            ->assertOk()
            ->assertSee('现金')
            ->assertSee('¥ 0.00')
            ->assertDontSee('还没有账户，请先设置账户再开始记账。');
    }

    public function test_chart_widgets_render_visible_zero_data_without_the_filament_empty_state(): void
    {
        Livewire::test(BookkeepingTrendChart::class, ['chartData' => [
            'labels' => ['2026-10-01', '2026-10-02'],
            'datasets' => [
                ['label' => '收入', 'data' => ['0.00', '0.00']],
                ['label' => '支出', 'data' => ['0.00', '0.00']],
            ],
        ]])
            ->assertOk()
            ->assertSee('暂无实际收支，图表显示零值基线')
            ->assertSee('<canvas', false)
            ->assertDontSee('暂无数据');

        Livewire::test(BookkeepingExpenseCategoriesChart::class, ['chartData' => [
            'labels' => ['暂无分类数据'],
            'percentages' => ['0.00'],
            'datasets' => [['label' => '暂无分类数据（占位，不计入统计）', 'data' => ['1.00']]],
            'placeholder' => true,
        ]])
            ->assertOk()
            ->assertSee('暂无分类数据。图表为占位轮廓，不计入支出统计。')
            ->assertSee('<canvas', false);

        Livewire::test(BookkeepingIncomeCategoriesChart::class, ['chartData' => [
            'labels' => ['暂无分类数据'],
            'datasets' => [['label' => '暂无分类数据（占位，不计入统计）', 'data' => ['0.00']]],
            'placeholder' => true,
        ]])
            ->assertOk()
            ->assertSee('暂无分类数据。图表显示零值基线，不计入收入统计。')
            ->assertSee('<canvas', false);
    }

    public function test_authorized_overview_renders_a_filament_table_with_only_ten_non_voided_recent_entries(): void
    {
        $this->travelTo(Carbon::parse('2026-10-16 10:00:00', 'Asia/Shanghai'));
        $user = $this->authorizedUser();
        $account = Account::factory()->create(['name' => '现金']);
        $category = Category::factory()->create(['name' => '餐饮', 'direction' => 'expense']);
        $entries = Entry::factory()->count(11)->for($account)->for($category)->for($user, 'createdBy')->create([
            'occurred_on' => '2026-10-16',
            'description' => '有效记录',
            'updated_by' => $user->id,
        ]);
        $voidedEntry = Entry::factory()->for($account)->for($category)->for($user, 'createdBy')->create([
            'occurred_on' => '2026-10-16',
            'description' => '已作废记录',
            'voided_at' => now(),
            'updated_by' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(BookkeepingOverview::class)
            ->set('endDate', '2026-10-16')
            ->assertOk()
            ->assertSee('最近记录')
            ->assertSee('日期')
            ->assertSee('方向')
            ->assertSee('分类')
            ->assertSee('账户')
            ->assertSee('金额')
            ->assertSee('说明')
            ->assertCanSeeTableRecords($entries->sortByDesc('id')->take(10))
            ->assertCanNotSeeTableRecords($entries->sortByDesc('id')->skip(10))
            ->assertCanNotSeeTableRecords([$voidedEntry]);
    }

    public function test_overview_combines_categories_outside_the_chart_limit_into_other(): void
    {
        $overview = app(BookkeepingOverview::class);
        $categories = array_map(fn (int $id): array => [
            'id' => $id,
            'name' => '分类 '.$id,
            'direction' => 'expense',
            'amount' => '1.00',
            'percentage' => '11.11',
        ], range(1, 9));

        $chartCategories = $overview->chartCategories($categories, 'expense');

        $this->assertCount(8, $chartCategories);
        $this->assertSame('其他', $chartCategories[7]['name']);
        $this->assertNull($chartCategories[7]['id']);
        $this->assertSame('2.00', $chartCategories[7]['amount']);
        $this->assertSame('22.22', $chartCategories[7]['percentage']);
    }

    public function test_income_and_account_charts_assign_a_distinct_color_to_each_bar(): void
    {
        $overview = app(BookkeepingOverview::class);
        $chartData = $overview->chartData([
            'categories' => [
                ['id' => 1, 'name' => '饭团', 'direction' => 'income', 'amount' => '100.00', 'percentage' => null],
                ['id' => 2, 'name' => '酱香饼', 'direction' => 'income', 'amount' => '80.00', 'percentage' => null],
            ],
            'trend' => ['labels' => [], 'datasets' => []],
            'accounts' => [
                ['id' => 1, 'name' => '微信', 'balance' => '100.00'],
                ['id' => 2, 'name' => '支付宝', 'balance' => '80.00'],
            ],
        ]);

        $this->assertSame(['#059669', '#0ea5e9'], $chartData['income']['datasets'][0]['backgroundColor']);
        $this->assertSame(['#2563eb', '#0891b2'], $chartData['accounts']['datasets'][0]['backgroundColor']);
    }

    private function authorizedUser(): User
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $server = McpServer::factory()->create(['module_key' => 'zhendianpu']);
        $membership = ['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'writer', 'created_at' => now(), 'updated_at' => now()];

        DB::table('workspace_members')->insert($membership);
        DB::table('mcp_server_access')->insert([...$membership, 'server_id' => $server->id]);

        return $user;
    }
}

class RenderableBookkeepingOverview extends BookkeepingOverview
{
    public static function canAccess(): bool
    {
        return true;
    }

    public function canWrite(): bool
    {
        return true;
    }

    public function hasValidDateRange(): bool
    {
        return true;
    }

    public function overview(): array
    {
        return [
            'totals' => ['income' => '100.00', 'expense' => '50.00', 'balance' => '50.00'],
            'categories' => [],
            'trend' => self::zeroTrend(),
            'accounts' => [],
            'account_total' => '50.00',
            'recent' => new Collection,
        ];
    }

    /** @return array{labels: list<string>, datasets: list<array{label: string, data: list<string>}>} */
    protected static function zeroTrend(): array
    {
        return [
            'labels' => ['2026-10-01', '2026-10-02'],
            'datasets' => [
                ['label' => '收入', 'data' => ['0.00', '0.00']],
                ['label' => '支出', 'data' => ['0.00', '0.00']],
            ],
        ];
    }
}

class EmptyRenderableBookkeepingOverview extends RenderableBookkeepingOverview
{
    public function overview(): array
    {
        return [
            'totals' => ['income' => '0.00', 'expense' => '0.00', 'balance' => '0.00'],
            'categories' => [],
            'trend' => self::zeroTrend(),
            'accounts' => [],
            'account_total' => '125.00',
            'recent' => new Collection,
        ];
    }
}

class ZeroBalanceAccountsRenderableBookkeepingOverview extends EmptyRenderableBookkeepingOverview
{
    public function overview(): array
    {
        return [
            ...parent::overview(),
            'accounts' => [['name' => '现金', 'balance' => '0.00']],
        ];
    }
}

class ReadOnlyEmptyRenderableBookkeepingOverview extends EmptyRenderableBookkeepingOverview
{
    public function canWrite(): bool
    {
        return false;
    }
}
