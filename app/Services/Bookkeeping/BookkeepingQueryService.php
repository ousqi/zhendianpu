<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookkeepingQueryService
{
    private const int MAX_DAILY_TREND_LABELS = 31;

    private const int MAX_TREND_LABELS = 53;

    private const int MAX_WEEKLY_TREND_DAYS = 365;

    private const int MAX_DATE_RANGE_DAYS = 1096;

    public function __construct(private BookkeepingService $service, private BookkeepingAccountService $accounts) {}

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function context(AccessContext $context, array $arguments): array
    {
        $this->readable($context);
        if ($arguments !== []) {
            throw ValidationException::withMessages(['arguments' => '获取上下文不接受参数。']);
        }

        return ['ok' => true, 'request_id' => $context->requestId, 'date' => now('Asia/Shanghai')->toDateString(), 'timezone' => 'Asia/Shanghai', 'currency' => 'CNY',
            'can_write' => $this->service->canWrite($context), 'categories' => Category::query()->where('is_active', true)->orderBy('direction')->orderBy('name')
                ->get(['id', 'name', 'direction'])->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name, 'direction' => $category->direction])->all(),
            'default_account_id' => Account::query()->where('is_default', true)->value('id'), 'accounts' => Account::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'type', 'is_default'])->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'is_default' => $account->is_default])->all()];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function entries(AccessContext $context, array $arguments): array
    {
        $this->readable($context);
        $input = $this->filters($arguments, true);
        $query = $this->query($input)->with(['category', 'account'])->orderByDesc('occurred_on')->orderByDesc('id');
        $rows = $query->paginate($input['limit'] ?? 20)->through(fn (Entry $entry): array => $this->service->present($entry));

        return ['ok' => true, 'request_id' => $context->requestId, 'period' => ['start' => $input['start_date'], 'end' => $input['end_date']],
            'records' => $rows->items(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'summary' => $this->totals($this->summaryQuery($input))];
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function summary(AccessContext $context, array $arguments): array
    {
        $this->readable($context);
        $input = $this->filters($arguments, false);
        $summary = $this->summaryData($input);

        return ['ok' => true, 'request_id' => $context->requestId, 'period' => ['start' => $input['start_date'], 'end' => $input['end_date']],
            'totals' => $summary['totals'], 'categories' => $summary['categories']];
    }

    /** @param array<string, mixed> $arguments @return array{totals: array{income: string, expense: string, balance: string}, categories: list<array{id: int, name: string, direction: string, amount: string, percentage: ?string}>, trend: array<string, mixed>, accounts: list<array{id: int, name: string, balance: string}>, account_total: string} */
    public function dashboard(AccessContext $context, array $arguments): array
    {
        $this->readable($context);
        $input = $this->filters($arguments, false);
        $summary = $this->summaryData($input);
        $query = $this->summaryQuery($input);
        $accounts = $this->accounts->accountsWithBalances()
            ->where(function (Builder $query): void {
                $query->where('bookkeeping_accounts.is_active', true)
                    ->orWhereNotNull('entry_balances.account_id')
                    ->orWhereNotNull('outgoing_balances.source_account_id')
                    ->orWhereNotNull('incoming_balances.destination_account_id');
            })->orderBy('sort_order')->orderBy('name')->get(['bookkeeping_accounts.id', 'bookkeeping_accounts.name', 'balance'])
            ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => bcadd((string) $account->balance, '0', 2)])->all();

        return [...$summary, 'trend' => $this->trend($query, $input['start_date'], $input['end_date']), 'accounts' => $accounts,
            'account_total' => array_reduce($accounts, fn (string $total, array $account): string => bcadd($total, $account['balance'], 2), '0.00')];
    }

    /** @param array<string, mixed> $input */
    public function summaryFor(array $input): array
    {
        return $this->totals($this->summaryQuery($this->filters($input, false)));
    }

    public function totalsFor(Builder $query): array
    {
        return $this->totals($query);
    }

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    private function filters(array $arguments, bool $allowsPagination): array
    {
        $rules = ['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'], 'direction' => ['nullable', 'in:income,expense'],
            'category_id' => ['nullable', 'integer'], 'account_id' => ['nullable', 'integer'], 'keyword' => ['nullable', 'string', 'max:1000'], 'include_voided' => ['nullable', 'boolean']];
        if ($allowsPagination) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['limit'] = ['nullable', 'integer', 'min:1', 'max:100'];
        }
        if (array_diff(array_keys($arguments), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['arguments' => '存在不支持的查询参数。']);
        }
        $input = Validator::make($arguments, $rules)->validate();
        $input['end_date'] ??= now('Asia/Shanghai')->toDateString();
        $input['start_date'] ??= now('Asia/Shanghai')->subDays(29)->toDateString();
        if ($input['start_date'] > $input['end_date']) {
            throw ValidationException::withMessages(['start_date' => '开始日期不得晚于结束日期。']);
        }
        if (Carbon::parse($input['start_date'], 'Asia/Shanghai')->diffInDays(Carbon::parse($input['end_date'], 'Asia/Shanghai')) + 1 > self::MAX_DATE_RANGE_DAYS) {
            throw ValidationException::withMessages(['end_date' => '日期范围最多为 '.self::MAX_DATE_RANGE_DAYS.' 天。']);
        }

        return $input;
    }

    /** @param array<string, mixed> $input */
    private function query(array $input): Builder
    {
        return Entry::query()->whereBetween('bookkeeping_entries.occurred_on', [$input['start_date'], $input['end_date']])
            ->when(empty($input['include_voided']), fn (Builder $query): Builder => $query->whereNull('bookkeeping_entries.voided_at'))
            ->when(isset($input['direction']), fn (Builder $query): Builder => $query->where('bookkeeping_entries.direction', $input['direction']))
            ->when(isset($input['category_id']), fn (Builder $query): Builder => $query->where('bookkeeping_entries.category_id', $input['category_id']))
            ->when(isset($input['account_id']), fn (Builder $query): Builder => $query->where('bookkeeping_entries.account_id', $input['account_id']))
            ->when(isset($input['keyword']), fn (Builder $query): Builder => $query->where('bookkeeping_entries.description', 'like', '%'.$input['keyword'].'%'));
    }

    private function totals(Builder $query): array
    {
        $amounts = (clone $query)->whereNull('bookkeeping_entries.voided_at')->selectRaw("COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE 0 END), 0) as income")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'expense' THEN amount ELSE 0 END), 0) as expense")->first();
        $income = bcadd((string) $amounts->income, '0', 2);
        $expense = bcadd((string) $amounts->expense, '0', 2);

        return ['income' => $income, 'expense' => $expense, 'balance' => bcsub($income, $expense, 2)];
    }

    /** @param array<string, mixed> $input @return array{totals: array{income: string, expense: string, balance: string}, categories: list<array{id: int, name: string, direction: string, amount: string, percentage: ?string}>} */
    private function summaryData(array $input): array
    {
        $query = $this->summaryQuery($input);
        $totals = $this->totals($query);
        $categories = (clone $query)->join('bookkeeping_categories', 'bookkeeping_entries.category_id', '=', 'bookkeeping_categories.id')
            ->selectRaw('bookkeeping_categories.id, bookkeeping_categories.name, bookkeeping_entries.direction, SUM(bookkeeping_entries.amount) as total')
            ->groupBy('bookkeeping_categories.id', 'bookkeeping_categories.name', 'bookkeeping_entries.direction')->orderByDesc('total')->get();

        return ['totals' => $totals, 'categories' => $categories->map(function (object $row) use ($totals): array {
            $amount = bcadd((string) $row->total, '0', 2);

            return ['id' => $row->id, 'name' => $row->name, 'direction' => $row->direction, 'amount' => $amount,
                'percentage' => $row->direction === 'expense' && bccomp($totals['expense'], '0.00', 2) === 1 ? bcdiv(bcmul($amount, '100', 2), $totals['expense'], 2) : null];
        })->values()->all()];
    }

    /** @return array{labels: list<string>, datasets: list<array{label: string, data: list<string>, borderColor: string, backgroundColor: string, tension: float, fill: bool}>} */
    private function trend(Builder $query, string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate, 'Asia/Shanghai')->startOfDay();
        $end = Carbon::parse($endDate, 'Asia/Shanghai')->startOfDay();
        $days = $start->diffInDays($end) + 1;
        $unit = $days <= self::MAX_DAILY_TREND_LABELS ? 'day' : ($days <= self::MAX_WEEKLY_TREND_DAYS ? 'week' : 'month');
        $totals = [];

        (clone $query)->select('occurred_on', 'direction')->selectRaw('SUM(amount) as total')->groupBy('occurred_on', 'direction')->get()
            ->each(function (object $row) use (&$totals, $unit): void {
                $bucket = $this->trendBucket(Carbon::parse($row->occurred_on, 'Asia/Shanghai'), $unit);
                $totals[$bucket][$row->direction] = bcadd($totals[$bucket][$row->direction] ?? '0.00', (string) $row->total, 2);
            });

        $labels = [];
        $income = [];
        $expense = [];
        for ($date = $this->trendStart($start, $unit); $date->lessThanOrEqualTo($end); $date = $this->nextTrendDate($date, $unit)) {
            if (count($labels) >= self::MAX_TREND_LABELS) {
                break;
            }

            $bucket = $this->trendBucket($date, $unit);
            $labels[] = $date->format('Y-m-d');
            $income[] = $totals[$bucket]['income'] ?? '0.00';
            $expense[] = $totals[$bucket]['expense'] ?? '0.00';
        }

        return ['labels' => $labels, 'datasets' => [
            ['label' => '收入', 'data' => $income, 'borderColor' => '#059669', 'backgroundColor' => 'rgba(5, 150, 105, 0.15)', 'tension' => 0.25, 'fill' => true],
            ['label' => '支出', 'data' => $expense, 'borderColor' => '#e11d48', 'backgroundColor' => 'rgba(225, 29, 72, 0.12)', 'tension' => 0.25, 'fill' => true],
        ]];
    }

    private function trendBucket(Carbon $date, string $unit): string
    {
        return match ($unit) {
            'day' => $date->toDateString(),
            'week' => $date->copy()->startOfWeek()->toDateString(),
            default => $date->startOfMonth()->toDateString(),
        };
    }

    private function trendStart(Carbon $date, string $unit): Carbon
    {
        return match ($unit) {
            'week' => $date->copy()->startOfWeek(),
            'month' => $date->copy()->startOfMonth(),
            default => $date->copy(),
        };
    }

    private function nextTrendDate(Carbon $date, string $unit): Carbon
    {
        return match ($unit) {
            'week' => $date->copy()->addWeek(),
            'month' => $date->copy()->addMonth(),
            default => $date->copy()->addDay(),
        };
    }

    /** @param array<string, mixed> $input */
    private function summaryQuery(array $input): Builder
    {
        return $this->query([...$input, 'include_voided' => false]);
    }

    private function readable(AccessContext $context): void
    {
        abort_unless($this->service->canRead($context), 403, 'FORBIDDEN');
    }
}
