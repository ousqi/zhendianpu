<?php

namespace App\Services\Bookkeeping;

use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class MonthlyBookkeepingReportService
{
    private const int RECENT_ENTRY_LIMIT = 10;

    private const int RANKING_LIMIT = 10;

    /** @var array{income: list<string>, expense: list<string>} */
    private const array CATEGORY_COLORS = [
        'income' => ['#0f766e', '#0e7490', '#2563eb', '#4f46e5', '#7c3aed', '#9333ea', '#c026d3', '#be123c', '#c2410c', '#a16207'],
        'expense' => ['#b91c1c', '#e11d48', '#dc2626', '#ea580c', '#f97316', '#d97706', '#b45309', '#9f1239', '#9d174d', '#c026d3'],
    ];

    private const string OTHER_CATEGORY_COLOR = '#94a3b8';

    public function generate(Ledger $ledger, ?string $period = null): void
    {
        [$startDate] = $this->period($ledger, $period);
        $periodKey = $startDate->format('Y-m');
        $availablePeriods = $this->availablePeriods($ledger, $periodKey);
        $latestHtml = '';

        foreach ($availablePeriods as $availablePeriod) {
            $html = $this->render($ledger, $availablePeriod, $availablePeriods);
            Storage::disk('local')->put($this->path($ledger, $availablePeriod), $html);

            if ($availablePeriod === $periodKey) {
                $latestHtml = $html;
            }
        }

        Storage::disk('local')->put($this->path($ledger), $latestHtml);
    }

    /** @param list<string> $availablePeriods */
    private function render(Ledger $ledger, string $period, array $availablePeriods): string
    {
        [$startDate, $endDate] = $this->period($ledger, $period);
        $input = ['start_date' => $startDate->toDateString(), 'end_date' => $endDate->toDateString()];
        $entries = $this->entriesQuery($ledger, $input);
        $totals = $this->totals($ledger, $input);
        $categories = $this->categoryRankings($ledger, $input, $totals);
        $daily = $this->dailyCashFlow($ledger, $input, $startDate, $endDate);
        $rankings = $this->entryRankings($entries);
        $recent = (clone $entries)->with('category:id,name')->orderByDesc('occurred_on')->orderByDesc('id')->limit(self::RECENT_ENTRY_LIMIT)->get()
            ->map(fn (Entry $entry): array => ['date' => $entry->occurred_on->toDateString(), 'direction' => $entry->direction, 'amount' => bcadd((string) $entry->amount, '0', 2), 'category' => $entry->category->name, 'description' => $entry->description])->all();

        return view('reports.monthly-bookkeeping', [
            'ledger' => ['name' => $ledger->name, 'currency' => $ledger->currency],
            'period' => ['key' => $period, 'label' => $startDate->format('Y 年 n 月'), 'start' => $input['start_date'], 'end' => $input['end_date']],
            'totals' => $totals,
            'comparison' => $this->comparisonAnalytics($ledger, $startDate, $totals),
            'categories' => $categories,
            'daily' => $daily,
            'rankings' => $rankings,
            'recent' => $recent,
            'navigation' => $this->navigation($ledger, $availablePeriods, $period),
        ])->render();
    }

    public function path(Ledger $ledger, ?string $period = null): string
    {
        return 'reports/monthly/'.$ledger->public_id.'/'.($period === null ? 'latest' : $period).'.html';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Ledger $ledger, ?string $period): array
    {
        if ($period === null) {
            $start = CarbonImmutable::now($ledger->timezone)->subMonthNoOverflow()->startOfMonth();

            return [$start, $start->endOfMonth()];
        }
        $date = CarbonImmutable::createFromFormat('!Y-m', $period, $ledger->timezone);
        $errors = CarbonImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m') !== $period) {
            throw ValidationException::withMessages(['period' => '期间必须采用 YYYY-MM 格式。']);
        }

        return [$date->startOfMonth(), $date->endOfMonth()];
    }

    /** @param array{start_date: string, end_date: string} $input */
    private function entriesQuery(Ledger $ledger, array $input): Builder
    {
        return Entry::query()->where('bookkeeping_entries.ledger_id', $ledger->id)->whereNull('bookkeeping_entries.voided_at')->whereBetween('bookkeeping_entries.occurred_on', [$input['start_date'], $input['end_date']]);
    }

    /** @param array{start_date: string, end_date: string} $input @return array{income: string, expense: string, balance: string} */
    private function totals(Ledger $ledger, array $input): array
    {
        $amounts = $this->entriesQuery($ledger, $input)->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) as income")->selectRaw("COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) as expense")->first();
        $refunds = $this->refundsQuery($ledger, $input)->selectRaw("COALESCE(SUM(CASE WHEN bookkeeping_entries.direction = 'income' THEN bookkeeping_refunds.amount ELSE 0 END), 0) as income")->selectRaw("COALESCE(SUM(CASE WHEN bookkeeping_entries.direction = 'expense' THEN bookkeeping_refunds.amount ELSE 0 END), 0) as expense")->first();
        $income = bcsub((string) $amounts->income, (string) $refunds->income, 2);
        $expense = bcsub((string) $amounts->expense, (string) $refunds->expense, 2);

        return ['income' => $income, 'expense' => $expense, 'balance' => bcsub($income, $expense, 2)];
    }

    /** @return array{month: array<string, mixed>, year: array<string, mixed>, chart: array{groups: list<array<string, mixed>>, available: bool}} */
    private function comparisonAnalytics(Ledger $ledger, CarbonImmutable $startDate, array $currentTotals): array
    {
        $periods = [
            'month' => ['label' => '环比', 'date' => $startDate->subMonthNoOverflow()->startOfMonth()],
            'year' => ['label' => '同比', 'date' => $startDate->subYear()->startOfMonth()],
        ];
        $comparisons = [];
        $chartGroups = [[
            'label' => '本期',
            'period_label' => $startDate->format('Y 年 n 月'),
            ...$currentTotals,
        ]];

        foreach ($periods as $key => $period) {
            $input = ['start_date' => $period['date']->toDateString(), 'end_date' => $period['date']->endOfMonth()->toDateString()];
            $available = $this->hasValidTransactions($ledger, $input);
            $baseline = $available ? $this->totals($ledger, $input) : null;
            $comparisons[$key] = [
                'label' => $period['label'],
                'period_label' => $period['date']->format('Y 年 n 月'),
                'available' => $available,
                'metrics' => $this->comparisonMetrics($currentTotals, $baseline),
            ];
            if ($available) {
                $chartGroups[] = ['label' => $period['label'], 'period_label' => $period['date']->format('Y 年 n 月'), ...$baseline];
            }
        }

        $maximum = '0.00';
        foreach ($chartGroups as $group) {
            $maximum = $this->maximum($maximum, $group['income'], $group['expense'], $group['balance']);
        }
        foreach ($chartGroups as &$group) {
            $group['income_height'] = $this->chartHeight($group['income'], $maximum);
            $group['expense_height'] = $this->chartHeight($group['expense'], $maximum);
            $group['balance_height'] = $this->chartHeight($group['balance'], $maximum);
        }
        unset($group);

        return [...$comparisons, 'chart' => ['groups' => $chartGroups, 'available' => count($chartGroups) > 1]];
    }

    /** @param array{start_date: string, end_date: string} $input */
    private function hasValidTransactions(Ledger $ledger, array $input): bool
    {
        return $this->entriesQuery($ledger, $input)->exists() || $this->refundsQuery($ledger, $input)->exists();
    }

    /** @param array{income: string, expense: string, balance: string}|null $baseline @return list<array{key: string, label: string, current: string, baseline: ?string, delta: ?string, rate: ?string, trend: string, tone: string, message: string}> */
    private function comparisonMetrics(array $current, ?array $baseline): array
    {
        $labels = ['income' => '净收入', 'expense' => '净支出', 'balance' => '期间结余'];
        $metrics = [];
        foreach ($labels as $key => $label) {
            if ($baseline === null) {
                $metrics[] = ['key' => $key, 'label' => $label, 'current' => $current[$key], 'baseline' => null, 'delta' => null, 'rate' => null, 'trend' => 'unavailable', 'tone' => 'neutral', 'message' => '可比期间暂无有效交易'];

                continue;
            }
            $delta = bcsub($current[$key], $baseline[$key], 2);
            $trend = bccomp($delta, '0.00', 2) > 0 ? 'up' : (bccomp($delta, '0.00', 2) < 0 ? 'down' : 'flat');
            $favorable = $key === 'expense' ? $trend === 'down' : $trend === 'up';
            $rate = bccomp($baseline[$key], '0.00', 2) === 0 ? null : bcdiv(bcmul($delta, '100', 4), $this->absolute($baseline[$key]), 1);
            $message = $rate === null ? (bccomp($delta, '0.00', 2) === 0 ? '基期为 0，持平' : '基期为 0，无法计算百分比') : ($rate.'%');
            $metrics[] = ['key' => $key, 'label' => $label, 'current' => $current[$key], 'baseline' => $baseline[$key], 'delta' => $delta, 'rate' => $rate, 'trend' => $trend, 'tone' => $trend === 'flat' ? 'neutral' : ($favorable ? 'favorable' : 'unfavorable'), 'message' => $message];
        }

        return $metrics;
    }

    /** @param array{income: string, expense: string, balance: string} $totals @return array{income: list<array<string, mixed>>, expense: list<array<string, mixed>>} */
    private function categoryRankings(Ledger $ledger, array $input, array $totals): array
    {
        $categories = $this->entriesQuery($ledger, $input)->join('bookkeeping_categories', 'bookkeeping_entries.category_id', '=', 'bookkeeping_categories.id')->selectRaw('bookkeeping_categories.id, bookkeeping_categories.name, bookkeeping_entries.direction, SUM(bookkeeping_entries.amount) as total')->groupBy('bookkeeping_categories.id', 'bookkeeping_categories.name', 'bookkeeping_entries.direction')->get()->keyBy('id');
        foreach ($this->refundsQuery($ledger, $input)->join('bookkeeping_categories', 'bookkeeping_entries.category_id', '=', 'bookkeeping_categories.id')->selectRaw('bookkeeping_entries.category_id, bookkeeping_categories.name, bookkeeping_entries.direction, SUM(bookkeeping_refunds.amount) as total')->groupBy('bookkeeping_entries.category_id', 'bookkeeping_categories.name', 'bookkeeping_entries.direction')->get() as $refund) {
            if (! $categories->has($refund->category_id)) {
                $categories->put($refund->category_id, (object) ['name' => $refund->name, 'direction' => $refund->direction, 'total' => '0.00']);
            }
            $categories[$refund->category_id]->total = bcsub((string) $categories[$refund->category_id]->total, (string) $refund->total, 2);
        }
        $rankings = ['income' => [], 'expense' => []];
        foreach ($categories as $category) {
            $rankings[$category->direction][] = ['name' => $category->name, 'amount' => bcadd((string) $category->total, '0', 2), 'is_synthetic' => false];
        }

        return ['income' => $this->limitCategoryRanking($rankings['income'], $totals['income'], 'income'), 'expense' => $this->limitCategoryRanking($rankings['expense'], $totals['expense'], 'expense')];
    }

    /** @param list<array{name: string, amount: string, is_synthetic: bool}> $categories @return list<array<string, bool|int|string>> */
    private function limitCategoryRanking(array $categories, string $total, string $direction): array
    {
        usort($categories, fn (array $left, array $right): int => bccomp($right['amount'], $left['amount'], 2));
        $visible = array_slice($categories, 0, self::RANKING_LIMIT);
        $remaining = array_slice($categories, self::RANKING_LIMIT);
        if ($remaining !== []) {
            $visible[] = ['name' => '其他', 'amount' => array_reduce($remaining, fn (string $amount, array $category): string => bcadd($amount, $category['amount'], 2), '0.00'), 'is_synthetic' => true];
        }
        $start = 0;

        return array_map(function (array $category, int $index) use ($total, $direction, &$start): array {
            $percentage = $this->percentage($category['amount'], $total);
            $portion = $this->barPercentage($percentage);
            $segment = ['start' => $start, 'end' => min(100, $start + $portion)];
            $start = $segment['end'];

            return [...$category, 'percentage' => $percentage, 'bar_percentage' => $portion, 'donut_start' => $segment['start'], 'donut_end' => $segment['end'], 'color' => $this->categoryColor($direction, $index, $category['is_synthetic'])];
        }, $visible, array_keys($visible));
    }

    private function categoryColor(string $direction, int $index, bool $isSynthetic): string
    {
        if ($isSynthetic) {
            return self::OTHER_CATEGORY_COLOR;
        }

        $palette = self::CATEGORY_COLORS[$direction];

        return $palette[$index % count($palette)];
    }

    private function percentage(string $amount, string $total): string
    {
        if (bccomp($amount, '0.00', 2) <= 0 || bccomp($total, '0.00', 2) <= 0) {
            return '0.0';
        }

        return bcdiv(bcmul($amount, '100', 4), $total, 1);
    }

    private function barPercentage(string $percentage): int
    {
        return max(0, min(100, (int) bcadd($percentage, '0.5', 0)));
    }

    /** @param array{start_date: string, end_date: string} $input @return array<string, mixed> */
    private function dailyCashFlow(Ledger $ledger, array $input, CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $entries = $this->entriesQuery($ledger, $input)->selectRaw("occurred_on, COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) as income, COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) as expense")->groupBy('occurred_on')->get()->keyBy(fn (object $row): string => CarbonImmutable::parse($row->occurred_on)->toDateString());
        $refunds = $this->refundsQuery($ledger, $input)->selectRaw("bookkeeping_refunds.occurred_on, COALESCE(SUM(CASE WHEN bookkeeping_entries.direction = 'income' THEN bookkeeping_refunds.amount ELSE 0 END), 0) as income, COALESCE(SUM(CASE WHEN bookkeeping_entries.direction = 'expense' THEN bookkeeping_refunds.amount ELSE 0 END), 0) as expense")->groupBy('bookkeeping_refunds.occurred_on')->get()->keyBy(fn (object $row): string => CarbonImmutable::parse($row->occurred_on)->toDateString());
        $rows = [];
        $maximum = '0.00';
        $runningBalance = '0.00';
        for ($date = $startDate; $date->lessThanOrEqualTo($endDate); $date = $date->addDay()) {
            $key = $date->toDateString();
            $income = bcsub((string) ($entries[$key]->income ?? '0'), (string) ($refunds[$key]->income ?? '0'), 2);
            $expense = bcsub((string) ($entries[$key]->expense ?? '0'), (string) ($refunds[$key]->expense ?? '0'), 2);
            $balance = bcsub($income, $expense, 2);
            $runningBalance = bcadd($runningBalance, $balance, 2);
            $maximum = $this->maximum($maximum, $income, $expense, $balance);
            $rows[] = ['date' => $key, 'label' => $date->format('n/j'), 'income' => $income, 'expense' => $expense, 'balance' => $balance, 'running_balance' => $runningBalance];
        }
        $trendMinimum = array_reduce(array_column($rows, 'running_balance'), fn (string $minimum, string $amount): string => bccomp($amount, $minimum, 2) < 0 ? $amount : $minimum, '0.00');
        $trendMaximum = array_reduce(array_column($rows, 'running_balance'), fn (string $maximum, string $amount): string => bccomp($amount, $maximum, 2) > 0 ? $amount : $maximum, '0.00');
        $trendRange = bcsub($trendMaximum, $trendMinimum, 2);
        $count = count($rows);
        foreach ($rows as $index => &$row) {
            $row['income_height'] = $this->chartHeight($row['income'], $maximum);
            $row['expense_height'] = $this->chartHeight($row['expense'], $maximum);
            $row['balance_height'] = $this->chartHeight($row['balance'], $maximum);
            $row['income_is_refund'] = bccomp($row['income'], '0.00', 2) === -1;
            $row['expense_is_refund'] = bccomp($row['expense'], '0.00', 2) === -1;
            $row['balance_is_negative'] = bccomp($row['balance'], '0.00', 2) === -1;
            $row['trend_x'] = $count > 1 ? (int) round(($index / ($count - 1)) * 100) : 50;
            $row['trend_y'] = bccomp($trendRange, '0.00', 2) === 0 ? 50 : 96 - (int) bcdiv(bcmul(bcsub($row['running_balance'], $trendMinimum, 2), '92', 4), $trendRange, 0);
            $row['trend_y'] = max(4, min(96, $row['trend_y']));
        }
        unset($row);
        $points = implode(' ', array_map(fn (array $row): string => $row['trend_x'].','.$row['trend_y'], $rows));
        $firstX = $rows[0]['trend_x'] ?? 0;
        $lastX = $rows[array_key_last($rows)]['trend_x'] ?? 100;

        return ['rows' => $rows, 'maximum' => $maximum, 'active_days' => $entries->keys()->merge($refunds->keys())->unique()->count(), 'active_entries' => $this->entriesQuery($ledger, $input)->count(), 'trend_points' => $points, 'trend_area' => $firstX.',100 '.$points.' '.$lastX.',100'];
    }

    private function maximum(string ...$amounts): string
    {
        return array_reduce($amounts, function (string $maximum, string $amount): string {
            $absolute = $this->absolute($amount);

            return bccomp($absolute, $maximum, 2) === 1 ? $absolute : $maximum;
        }, '0.00');
    }

    private function absolute(string $amount): string
    {
        return bccomp($amount, '0.00', 2) < 0 ? bcmul($amount, '-1', 2) : $amount;
    }

    private function chartHeight(string $amount, string $maximum): int
    {
        if (bccomp($amount, '0.00', 2) === 0 || bccomp($maximum, '0.00', 2) <= 0) {
            return 0;
        }

        return max(2, min(100, (int) bcadd(bcdiv(bcmul($this->absolute($amount), '100', 2), $maximum, 0), '0', 0)));
    }

    /** @return array{income: list<array{date: string, category: string, description: ?string, amount: string}>, expense: list<array{date: string, category: string, description: ?string, amount: string}>} */
    private function entryRankings(Builder $entries): array
    {
        $rankings = [];
        foreach (['income', 'expense'] as $direction) {
            $rankings[$direction] = (clone $entries)->where('direction', $direction)->with('category:id,name')->orderByDesc('amount')->orderByDesc('occurred_on')->orderByDesc('id')->limit(self::RANKING_LIMIT)->get()->map(fn (Entry $entry): array => ['date' => $entry->occurred_on->toDateString(), 'category' => $entry->category->name, 'description' => $entry->description, 'amount' => bcadd((string) $entry->amount, '0', 2)])->all();
        }

        return $rankings;
    }

    /** @return list<string> */
    private function availablePeriods(Ledger $ledger, string $period): array
    {
        $directory = 'reports/monthly/'.$ledger->public_id;

        return collect(Storage::disk('local')->files($directory))->map(fn (string $path): ?string => preg_match('#/(\d{4}-(?:0[1-9]|1[0-2]))\.html$#', $path, $matches) === 1 ? $matches[1] : null)->filter()->push($period)->unique()->sortDesc()->values()->all();
    }

    /** @param list<string> $availablePeriods @return array<string, mixed> */
    private function navigation(Ledger $ledger, array $availablePeriods, string $period): array
    {
        $baseUrl = route('reports.monthly.show', $ledger);
        $periods = array_map(function (string $availablePeriod) use ($baseUrl, $period): array {
            return ['key' => $availablePeriod, 'label' => CarbonImmutable::createFromFormat('!Y-m', $availablePeriod)->format('Y 年 n 月'), 'url' => $baseUrl.'?month='.$availablePeriod, 'selected' => $availablePeriod === $period];
        }, $availablePeriods);
        $selectedIndex = array_search($period, $availablePeriods, true);

        return ['base_url' => $baseUrl, 'periods' => $periods, 'previous' => is_int($selectedIndex) && isset($periods[$selectedIndex + 1]) ? ['label' => $periods[$selectedIndex + 1]['label'], 'url' => $periods[$selectedIndex + 1]['url']] : null, 'next' => is_int($selectedIndex) && $selectedIndex > 0 ? ['label' => $periods[$selectedIndex - 1]['label'], 'url' => $periods[$selectedIndex - 1]['url']] : null];
    }

    /** @param array{start_date: string, end_date: string} $input */
    private function refundsQuery(Ledger $ledger, array $input): Builder
    {
        return Refund::query()->join('bookkeeping_entries', 'bookkeeping_entries.id', '=', 'bookkeeping_refunds.entry_id')->where('bookkeeping_refunds.ledger_id', $ledger->id)->whereNull('bookkeeping_refunds.voided_at')->whereNull('bookkeeping_entries.voided_at')->whereBetween('bookkeeping_refunds.occurred_on', [$input['start_date'], $input['end_date']]);
    }
}
