<?php

namespace App\Filament\Pages;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Entry;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingQueryService;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Mcp\AccessResolver;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;
use UnitEnum;

class BookkeepingOverview extends Page implements HasTable
{
    use InteractsWithTable;

    private const int MAX_DATE_RANGE_DAYS = 1096;

    public const int CATEGORY_CHART_LIMIT = 7;

    protected static ?string $title = '收支概览';

    protected static string|UnitEnum|null $navigationGroup = '记账';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.bookkeeping-overview';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(BookkeepingService::class)->canAccess($user);
    }

    public string $startDate;

    public string $endDate;

    public string $range = 'month';

    public function mount(): void
    {
        $today = now('Asia/Shanghai');
        $this->startDate = $today->copy()->startOfMonth()->toDateString();
        $this->endDate = $today->toDateString();
    }

    public function useRange(string $range): void
    {
        $today = now('Asia/Shanghai');
        $ranges = [
            'week' => [$today->copy()->startOfWeek(), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$today->copy()->startOfYear(), $today->copy()],
        ];
        if (! array_key_exists($range, $ranges)) {
            return;
        }

        [$start, $end] = $ranges[$range];
        $this->range = $range;
        $this->startDate = $start->toDateString();
        $this->endDate = $end->toDateString();
        $this->resetErrorBag(['startDate', 'endDate']);
    }

    public function updatedStartDate(): void
    {
        $this->range = 'custom';
        $this->validateDateRange();
    }

    public function updatedEndDate(): void
    {
        $this->range = 'custom';
        $this->validateDateRange();
    }

    public function minimumSelectableDate(): string
    {
        return now('Asia/Shanghai')->subDays(self::MAX_DATE_RANGE_DAYS - 1)->toDateString();
    }

    public function maximumSelectableDate(): string
    {
        return now('Asia/Shanghai')->toDateString();
    }

    public function hasValidDateRange(): bool
    {
        return $this->dateRangeValidator()->passes();
    }

    public function canWrite(): bool
    {
        return app(BookkeepingService::class)->canWrite($this->context());
    }

    /** @param list<array{id: int, name: string, direction: string, amount: string, percentage: ?string}> $categories @return list<array{id: ?int, name: string, direction: string, amount: string, percentage: ?string}> */
    public function chartCategories(array $categories, string $direction): array
    {
        $categories = array_values(array_filter($categories, fn (array $category): bool => $category['direction'] === $direction));
        if (count($categories) <= self::CATEGORY_CHART_LIMIT) {
            return $categories;
        }

        $visibleCategories = array_slice($categories, 0, self::CATEGORY_CHART_LIMIT);
        $otherCategories = array_slice($categories, self::CATEGORY_CHART_LIMIT);
        $otherAmount = array_reduce($otherCategories, fn (string $total, array $category): string => bcadd($total, $category['amount'], 2), '0.00');
        $directionTotal = array_reduce($categories, fn (string $total, array $category): string => bcadd($total, $category['amount'], 2), '0.00');

        $visibleCategories[] = [
            'id' => null,
            'name' => '其他',
            'direction' => $direction,
            'amount' => $otherAmount,
            'percentage' => $direction === 'expense' && bccomp($directionTotal, '0.00', 2) === 1
                ? bcdiv(bcmul($otherAmount, '100', 2), $directionTotal, 2)
                : null,
        ];

        return $visibleCategories;
    }

    /** @param array{categories: list<array{id: int, name: string, direction: string, amount: string, percentage: ?string}>, trend: array<string, mixed>, accounts: list<array{id: int, name: string, balance: string}>} $overview @return array{trend: array<string, mixed>, expense: array<string, mixed>, income: array<string, mixed>, accounts: array<string, mixed>} */
    public function chartData(array $overview): array
    {
        $expenseCategories = collect($this->chartCategories($overview['categories'], 'expense'));
        $incomeCategories = collect($this->chartCategories($overview['categories'], 'income'));
        $accounts = collect($overview['accounts']);
        $incomeColors = ['#059669', '#0ea5e9', '#8b5cf6', '#f97316', '#eab308', '#ec4899', '#14b8a6', '#64748b'];
        $accountColors = ['#2563eb', '#0891b2', '#7c3aed', '#ea580c', '#ca8a04', '#db2777', '#0d9488', '#475569'];

        return [
            'trend' => $overview['trend'],
            'expense' => $expenseCategories->isEmpty() ? $this->emptyCategoryChartData('expense') : [
                'labels' => $expenseCategories->pluck('name')->all(),
                'percentages' => $expenseCategories->pluck('percentage')->map(fn (?string $percentage): string => $percentage ?? '0.00')->all(),
                'datasets' => [[
                    'label' => '支出',
                    'data' => $expenseCategories->pluck('amount')->all(),
                    'backgroundColor' => ['#e11d48', '#f97316', '#eab308', '#a855f7', '#0ea5e9', '#64748b', '#14b8a6', '#7c3aed'],
                ]],
            ],
            'income' => $incomeCategories->isEmpty() ? $this->emptyCategoryChartData('income') : [
                'labels' => $incomeCategories->pluck('name')->all(),
                'datasets' => [[
                    'label' => '收入',
                    'data' => $incomeCategories->pluck('amount')->all(),
                    'backgroundColor' => $incomeCategories->values()->map(fn (array $_category, int $index): string => $incomeColors[$index % count($incomeColors)])->all(),
                ]],
            ],
            'accounts' => $accounts->isEmpty() ? [] : [
                'labels' => $accounts->pluck('name')->all(),
                'datasets' => [[
                    'label' => '当前余额',
                    'data' => $accounts->pluck('balance')->all(),
                    'backgroundColor' => $accounts->values()->map(fn (array $_account, int $index): string => $accountColors[$index % count($accountColors)])->all(),
                ]],
            ],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->recentEntries())
            ->columns([
                TextColumn::make('occurred_on')->label('日期')->date('Y-m-d'),
                TextColumn::make('direction')->label('方向')->badge()->formatStateUsing(fn (string $state): string => $state === 'income' ? '收入' : '支出'),
                TextColumn::make('category.name')->label('分类'),
                TextColumn::make('account.name')->label('账户')->visibleFrom('md'),
                TextColumn::make('amount')->label('金额')->money('CNY')->prefix(fn (Entry $entry): string => $entry->direction === 'income' ? '+' : '-'),
                TextColumn::make('description')->label('说明')->wrap()->visibleFrom('md'),
            ])
            ->emptyStateHeading('当前期间暂无记录')
            ->emptyStateDescription('记一笔收支后，这里会显示最近的十条有效记录。')
            ->emptyStateActions([
                Action::make('addEntry')
                    ->label('去记一笔')
                    ->url(BookkeepingEntries::getUrl())
                    ->visible(fn (): bool => $this->canWrite())
                    ->button(),
            ])
            ->paginated(false);
    }

    /** @return array{totals: array{income: string, expense: string, balance: string}, categories: list<array<string, mixed>>, trend: array<string, mixed>, accounts: list<array<string, mixed>>, account_total: string} */
    public function overview(): array
    {
        $filters = ['start_date' => $this->startDate, 'end_date' => $this->endDate];

        return app(BookkeepingQueryService::class)->dashboard($this->context(), $filters);
    }

    private function context(): AccessContext
    {
        return app(AccessResolver::class)->resolve(auth()->user(), 'zhendianpu');
    }

    /** @return array{labels: list<string>, percentages: list<string>, datasets: list<array{label: string, data: list<string>, backgroundColor: string|list<string>, borderColor: string, borderWidth: int}>, placeholder: true} */
    private function emptyCategoryChartData(string $direction): array
    {
        $isExpense = $direction === 'expense';

        return [
            'labels' => ['暂无分类数据'],
            'percentages' => ['0.00'],
            'datasets' => [[
                'label' => '暂无分类数据（占位，不计入统计）',
                'data' => [$isExpense ? '1.00' : '0.00'],
                'backgroundColor' => $isExpense ? ['rgba(225, 29, 72, 0.18)'] : 'rgba(5, 150, 105, 0.14)',
                'borderColor' => $isExpense ? '#e11d48' : '#059669',
                'borderWidth' => 2,
            ]],
            'placeholder' => true,
        ];
    }

    private function recentEntries(): Builder
    {
        return Entry::query()
            ->with(['category:id,name', 'account:id,name'])
            ->whereBetween('occurred_on', [$this->startDate, $this->endDate])
            ->whereNull('voided_at')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(10);
    }

    private function validateDateRange(): void
    {
        $validator = $this->dateRangeValidator();
        if ($validator->fails()) {
            $this->setErrorBag($validator->errors());

            return;
        }

        $this->resetErrorBag(['startDate', 'endDate']);
    }

    private function dateRangeValidator(): ValidationValidator
    {
        return Validator::make([
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
        ], [
            'startDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:endDate', 'after_or_equal:'.$this->minimumSelectableDate()],
            'endDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:startDate', 'before_or_equal:'.$this->maximumSelectableDate()],
        ], [
            'startDate.before_or_equal' => '开始日期不得晚于结束日期。',
            'startDate.after_or_equal' => '开始日期不得早于可选范围。',
            'endDate.after_or_equal' => '结束日期不得早于开始日期。',
            'endDate.before_or_equal' => '结束日期不得晚于今天。',
        ])->after(function (ValidationValidator $validator): void {
            if ($validator->errors()->hasAny(['startDate', 'endDate'])) {
                return;
            }

            $days = Carbon::parse($this->startDate, 'Asia/Shanghai')->diffInDays(Carbon::parse($this->endDate, 'Asia/Shanghai')) + 1;
            if ($days > self::MAX_DATE_RANGE_DAYS) {
                $validator->errors()->add('endDate', '日期范围最多为 '.self::MAX_DATE_RANGE_DAYS.' 天。');
            }
        });
    }
}
