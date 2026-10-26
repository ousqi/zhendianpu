<?php

namespace Tests\Feature;

use App\Jobs\GenerateMonthlyBookkeepingReport;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Refund;
use App\Models\User;
use App\Services\Bookkeeping\MonthlyBookkeepingReportService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MonthlyBookkeepingReportTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'users',
        'bookkeeping_ledgers',
        'bookkeeping_categories',
        'bookkeeping_accounts',
        'bookkeeping_entries',
        'bookkeeping_refunds',
    ];

    public function test_job_materializes_a_report_with_refunds_in_their_own_month_and_excludes_voided_records(): void
    {
        Storage::fake('local');
        $ledger = Ledger::factory()->create(['name' => '春日账本']);
        $income = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '营业收入', 'direction' => 'income']);
        $expense = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '食材', 'direction' => 'expense']);
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $user = User::factory()->create();
        $incomeEntry = Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $income->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '100.00', 'direction' => 'income', 'occurred_on' => '2026-09-12']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $expense->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '40.00', 'direction' => 'expense', 'occurred_on' => '2026-09-15', 'description' => '新鲜食材']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $expense->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '999.00', 'direction' => 'expense', 'occurred_on' => '2026-09-16', 'voided_at' => now()]);
        Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $incomeEntry->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '10.00', 'occurred_on' => '2026-09-20']);
        Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $incomeEntry->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '20.00', 'occurred_on' => '2026-10-02']);
        Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $incomeEntry->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '5.00', 'occurred_on' => '2026-09-21', 'voided_at' => now()]);

        (new GenerateMonthlyBookkeepingReport($ledger, '2026-09'))->handle(app(MonthlyBookkeepingReportService::class));

        $archivePath = 'reports/monthly/'.$ledger->public_id.'/2026-09.html';
        $latestPath = 'reports/monthly/'.$ledger->public_id.'/latest.html';
        Storage::disk('local')->assertExists($archivePath);
        Storage::disk('local')->assertExists($latestPath);
        $html = Storage::disk('local')->get($latestPath);
        $this->assertStringContainsString('CNY 90.00', $html);
        $this->assertStringContainsString('CNY 40.00', $html);
        $this->assertStringContainsString('CNY 50.00', $html);
        $this->assertStringNotContainsString('999.00', $html);
        $this->assertStringContainsString('报告期间', $html);
        $this->assertStringContainsString('上一期', $html);
        $this->assertStringContainsString('最新', $html);
    }

    public function test_reports_require_authentication(): void
    {
        $ledger = Ledger::factory()->create();

        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->get(route('reports.monthly.show', $ledger))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_latest_and_selected_historical_static_reports(): void
    {
        Storage::fake('local');
        $published = Ledger::factory()->create();
        $missing = Ledger::factory()->create();
        Storage::disk('local')->put('reports/monthly/'.$published->public_id.'/latest.html', '<!doctype html><title>Published</title>');
        Storage::disk('local')->put('reports/monthly/'.$published->public_id.'/2026-09.html', '<!doctype html><title>September</title>');

        $this->actingAs(User::factory()->create());

        $this->get(route('reports.monthly.show', $published))->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertSee('Published', false);
        $this->get(route('reports.monthly.show', ['ledger' => $published, 'month' => '2026-09']))->assertOk()->assertSee('September', false);
        $this->get(route('reports.monthly.show', $missing))->assertNotFound();
        $this->get(route('reports.monthly.show', ['ledger' => $published, 'month' => '2026-08']))->assertNotFound();
        $this->get(route('reports.monthly.show', ['ledger' => $published, 'month' => '../../.env']))->assertNotFound();
    }

    public function test_authenticated_users_can_view_the_report_landing_page_with_report_availability(): void
    {
        Storage::fake('local');
        $published = Ledger::factory()->create(['name' => '春日账本', 'currency' => 'CNY']);
        Ledger::factory()->create(['name' => '夏日账本', 'currency' => 'USD']);
        Ledger::factory()->create(['name' => '已归档账本', 'status' => 'archived']);
        Storage::disk('local')->put('reports/monthly/'.$published->public_id.'/latest.html', '<!doctype html><title>Published</title>');

        $this->actingAs(User::factory()->create());

        $response = $this->get(route('reports.index'));

        $response->assertOk()
            ->assertSee('春日账本')
            ->assertSee('CNY')
            ->assertSee('最新报告已生成')
            ->assertSee(route('reports.monthly.show', $published), false)
            ->assertSee('夏日账本')
            ->assertSee('USD')
            ->assertSee('最新报告暂不可用')
            ->assertDontSee('已归档账本');
    }

    public function test_report_shows_daily_refund_adjustments_and_separate_top_ten_rankings(): void
    {
        Storage::fake('local');
        $ledger = Ledger::factory()->create(['currency' => 'CNY']);
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $user = User::factory()->create();
        $incomeEntries = [];

        for ($index = 1; $index <= 11; $index++) {
            $category = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '收入分类'.$index, 'direction' => 'income']);
            $incomeEntries[] = Entry::factory()->create([
                'ledger_id' => $ledger->id,
                'category_id' => $category->id,
                'account_id' => $account->id,
                'created_by' => $user->id,
                'updated_by' => $user->id,
                'amount' => (string) (1200 - ($index * 100)).'.00',
                'direction' => 'income',
                'occurred_on' => '2026-09-12',
                'description' => '收入记录'.$index,
            ]);
        }
        $expense = Category::factory()->create(['ledger_id' => $ledger->id, 'name' => '房租', 'direction' => 'expense']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $expense->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '880.00', 'direction' => 'expense', 'occurred_on' => '2026-09-15', 'description' => '九月房租']);
        Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $incomeEntries[0]->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '50.00', 'occurred_on' => '2026-09-20']);

        app(MonthlyBookkeepingReportService::class)->generate($ledger, '2026-09');

        $html = Storage::disk('local')->get('reports/monthly/'.$ledger->public_id.'/latest.html');
        $this->assertStringContainsString('每日净现金流', $html);
        $this->assertStringContainsString('2026-09-20：净收入 CNY -50.00', $html);
        $this->assertStringContainsString('收入分类 Top 10', $html);
        $this->assertStringContainsString('支出分类 Top 10', $html);
        $this->assertStringContainsString('最大收入记录 Top 10', $html);
        $this->assertStringContainsString('最大支出记录 Top 10', $html);
        $this->assertStringContainsString('收入分类1', $html);
        $this->assertStringContainsString('收入分类10', $html);
        $this->assertStringContainsString('其他', $html);
        $this->assertStringNotContainsString('收入分类11</span>', $html);
        $this->assertStringContainsString('收入记录1', $html);
        $this->assertStringContainsString('收入记录10', $html);
        $incomeRanking = explode('最大收入记录 Top 10', $html, 2)[1];
        $incomeRanking = explode('最大支出记录 Top 10', $incomeRanking, 2)[0];
        $this->assertStringNotContainsString('收入记录11', $incomeRanking);
        $this->assertStringContainsString('房租', $html);
    }

    public function test_report_calculates_month_over_month_and_year_over_year_comparisons_from_valid_period_activity(): void
    {
        Storage::fake('local');
        $ledger = Ledger::factory()->create(['currency' => 'CNY']);
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $user = User::factory()->create();
        $income = Category::factory()->create(['ledger_id' => $ledger->id, 'direction' => 'income']);
        $expense = Category::factory()->create(['ledger_id' => $ledger->id, 'direction' => 'expense']);
        $currentIncome = Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $income->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'income', 'amount' => '250.00', 'occurred_on' => '2026-09-05']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $expense->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'expense', 'amount' => '100.00', 'occurred_on' => '2026-09-06']);
        Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $currentIncome->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '50.00', 'occurred_on' => '2026-09-07']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $income->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'income', 'amount' => '100.00', 'occurred_on' => '2026-08-05']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $expense->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'expense', 'amount' => '200.00', 'occurred_on' => '2026-08-06']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $income->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'income', 'amount' => '100.00', 'occurred_on' => '2025-09-05']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $expense->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'expense', 'amount' => '50.00', 'occurred_on' => '2025-09-06']);

        app(MonthlyBookkeepingReportService::class)->generate($ledger, '2026-09');

        $html = Storage::disk('local')->get('reports/monthly/'.$ledger->public_id.'/latest.html');
        $this->assertStringContainsString('收支表现', $html);
        $this->assertStringContainsString('月度对比', $html);
        $this->assertStringContainsString('累计结余趋势', $html);
        $this->assertStringContainsString('对比 2026 年 8 月', $html);
        $this->assertStringContainsString('↑ 100.0%', $html);
        $this->assertStringContainsString('↓ -50.0%', $html);
        $this->assertStringContainsString('↑ 200.0%', $html);
        $this->assertStringContainsString('对比 2025 年 9 月', $html);
        $this->assertStringContainsString('↑ 200.0%', $html);
    }

    public function test_report_marks_comparisons_unavailable_when_older_periods_have_no_valid_transactions(): void
    {
        Storage::fake('local');
        $ledger = Ledger::factory()->create();
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $user = User::factory()->create();
        $income = Category::factory()->create(['ledger_id' => $ledger->id, 'direction' => 'income']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $income->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'income', 'amount' => '100.00', 'occurred_on' => '2026-09-05']);

        app(MonthlyBookkeepingReportService::class)->generate($ledger, '2026-09');

        $html = Storage::disk('local')->get('reports/monthly/'.$ledger->public_id.'/latest.html');
        $this->assertStringContainsString('可比期间暂无有效交易', $html);
        $this->assertStringContainsString('暂无可比月份', $html);
    }

    public function test_report_compares_a_zero_net_baseline_with_a_baseline_period_refund(): void
    {
        Storage::fake('local');
        $ledger = Ledger::factory()->create(['currency' => 'CNY']);
        $account = Account::factory()->create(['ledger_id' => $ledger->id]);
        $user = User::factory()->create();
        $income = Category::factory()->create(['ledger_id' => $ledger->id, 'direction' => 'income']);
        $baselineIncome = Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $income->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'income', 'amount' => '100.00', 'occurred_on' => '2026-08-05']);
        Refund::factory()->create(['ledger_id' => $ledger->id, 'entry_id' => $baselineIncome->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'amount' => '100.00', 'occurred_on' => '2026-08-12']);
        Entry::factory()->create(['ledger_id' => $ledger->id, 'category_id' => $income->id, 'account_id' => $account->id, 'created_by' => $user->id, 'updated_by' => $user->id, 'direction' => 'income', 'amount' => '100.00', 'occurred_on' => '2026-09-05']);

        app(MonthlyBookkeepingReportService::class)->generate($ledger, '2026-09');

        $html = Storage::disk('local')->get('reports/monthly/'.$ledger->public_id.'/latest.html');
        $this->assertStringContainsString('环比（2026 年 8 月）</th><td>CNY 0.00</td><td>CNY 0.00</td><td>CNY 0.00</td>', $html);
        $this->assertStringContainsString('基期为 0，无法计算百分比', $html);
    }

    public function test_generated_archives_include_switches_for_all_available_periods(): void
    {
        Storage::fake('local');
        $ledger = Ledger::factory()->create();
        $reports = app(MonthlyBookkeepingReportService::class);

        $reports->generate($ledger, '2026-09');
        $reports->generate($ledger, '2026-10');

        $archiveHtml = Storage::disk('local')->get('reports/monthly/'.$ledger->public_id.'/2026-09.html');
        $latestHtml = Storage::disk('local')->get($reports->path($ledger));
        $baseUrl = route('reports.monthly.show', $ledger);
        $this->assertStringContainsString($baseUrl.'?month=2026-09', $archiveHtml);
        $this->assertStringContainsString($baseUrl.'?month=2026-10', $archiveHtml);
        $this->assertStringContainsString('href="'.$baseUrl.'?month=2026-10" aria-label="查看2026 年 10 月，下一期">下一期 →</a>', $archiveHtml);
        $this->assertStringContainsString('>最新<', $latestHtml);
    }

    public function test_job_defaults_to_the_preceding_calendar_month_in_the_ledger_timezone(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-01 00:15:00', 'Asia/Shanghai'));
        $ledger = Ledger::factory()->create(['timezone' => 'Asia/Shanghai']);

        (new GenerateMonthlyBookkeepingReport($ledger))->handle(app(MonthlyBookkeepingReportService::class));

        $html = Storage::disk('local')->get('reports/monthly/'.$ledger->public_id.'/latest.html');
        $this->assertStringContainsString('2026 年 9 月', $html);
    }

    public function test_command_dispatches_one_job_for_each_active_ledger_and_excludes_archived_ledgers(): void
    {
        $first = Ledger::factory()->create(['status' => 'active']);
        $second = Ledger::factory()->create(['status' => 'active']);
        Ledger::factory()->create(['status' => 'archived']);
        Queue::fake([GenerateMonthlyBookkeepingReport::class]);

        $this->artisan('bookkeeping:generate-monthly-reports', ['--period' => '2026-09'])->expectsOutput('Dispatched 2 monthly bookkeeping report job(s) for 2026-09.')->assertSuccessful();

        Queue::assertPushed(GenerateMonthlyBookkeepingReport::class, fn (GenerateMonthlyBookkeepingReport $job): bool => $job->ledger->is($first) && $job->period === '2026-09');
        Queue::assertPushed(GenerateMonthlyBookkeepingReport::class, fn (GenerateMonthlyBookkeepingReport $job): bool => $job->ledger->is($second) && $job->period === '2026-09');
        Queue::assertPushedTimes(GenerateMonthlyBookkeepingReport::class, 2);
    }

    public function test_command_rejects_an_invalid_period(): void
    {
        $this->artisan('bookkeeping:generate-monthly-reports', ['--period' => '2026-13'])->expectsOutput('The --period option must use YYYY-MM format.')->assertFailed();
    }
}
