<x-filament-panels::page>
    @php
        $dateRangeValid = $this->hasValidDateRange();
    @endphp

    <div class="bookkeeping-overview grid gap-6">
        <x-filament::section>
            <div class="grid gap-4 xl:grid-cols-[1fr_auto] xl:items-end">
                <div class="grid gap-3">
                    <div class="flex flex-wrap gap-2" aria-label="日期范围">
                        @foreach (['week' => '本周', 'month' => '本月', 'last_month' => '上月', 'year' => '今年'] as $range => $label)
                            <button type="button" wire:click="useRange('{{ $range }}')" aria-pressed="{{ $this->range === $range ? 'true' : 'false' }}" @class(['rounded-lg px-3 py-1.5 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900', 'bg-primary-600 text-white shadow-sm hover:bg-primary-500' => $this->range === $range, 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700' => $this->range !== $range])>{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">开始日期<input type="date" wire:model.live="startDate" min="{{ $this->minimumSelectableDate() }}" max="{{ $this->maximumSelectableDate() }}" aria-describedby="start-date-format start-date-error" class="rounded-lg border-gray-300 bg-white shadow-sm dark:border-gray-600 dark:bg-gray-800"><span id="start-date-format" class="text-xs font-normal text-gray-500 dark:text-gray-400">当前值：{{ $this->startDate }}（yyyy-mm-dd）</span></label>
                        <label class="grid gap-1.5 text-sm font-medium text-gray-700 dark:text-gray-200">结束日期<input type="date" wire:model.live="endDate" min="{{ $this->minimumSelectableDate() }}" max="{{ $this->maximumSelectableDate() }}" aria-describedby="end-date-format end-date-error" class="rounded-lg border-gray-300 bg-white shadow-sm dark:border-gray-600 dark:bg-gray-800"><span id="end-date-format" class="text-xs font-normal text-gray-500 dark:text-gray-400">当前值：{{ $this->endDate }}（yyyy-mm-dd）</span></label>
                    </div>
                </div>
                <p class="text-sm text-gray-500 dark:text-gray-400">当前范围：{{ $this->startDate }} 至 {{ $this->endDate }}<br class="hidden xl:block">最多 1096 天</p>
            </div>
            @error('startDate') <p id="start-date-error" class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
            @error('endDate') <p id="end-date-error" class="mt-3 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p> @enderror
        </x-filament::section>

    @unless ($dateRangeValid)
        <p class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-300" role="alert">请修正日期范围后查看概览。</p>
    @else
        @php
         $overview = $this->overview();
         $filters = ['occurred_on' => ['start' => $this->startDate, 'end' => $this->endDate]];
          $expenseCategories = collect($this->chartCategories($overview['categories'], 'expense'));
          $incomeCategories = collect($this->chartCategories($overview['categories'], 'income'));
          $hasEntries = $expenseCategories->isNotEmpty() || $incomeCategories->isNotEmpty();
          $chartData = $this->chartData($overview);
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
        <a href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl(['tableFilters' => [...$filters, 'direction' => ['value' => 'income']]]) }}" class="rounded-xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-emerald-900 dark:bg-emerald-950/40">
            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">收入</p><p class="mt-2 text-2xl font-semibold tracking-tight text-emerald-700 dark:text-emerald-300">¥ {{ $overview['totals']['income'] }}</p><p class="mt-2 text-xs text-emerald-700/70 dark:text-emerald-300/70">查看本范围收入明细</p>
        </a>
        <a href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl(['tableFilters' => [...$filters, 'direction' => ['value' => 'expense']]]) }}" class="rounded-xl border border-rose-200 bg-rose-50 p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-rose-900 dark:bg-rose-950/40">
            <p class="text-sm font-medium text-rose-800 dark:text-rose-200">支出</p><p class="mt-2 text-2xl font-semibold tracking-tight text-rose-700 dark:text-rose-300">¥ {{ $overview['totals']['expense'] }}</p><p class="mt-2 text-xs text-rose-700/70 dark:text-rose-300/70">查看本范围支出明细</p>
        </a>
        <a href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl(['tableFilters' => $filters]) }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-gray-700 dark:bg-gray-900">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-200">期间结余</p><p class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">¥ {{ $overview['totals']['balance'] }}</p><p class="mt-2 text-xs text-gray-500 dark:text-gray-400">收入减支出，不等于经营利润</p>
        </a>
        <a href="{{ \App\Filament\Pages\BookkeepingAccounts::getUrl() }}" class="rounded-xl border border-blue-200 bg-blue-50 p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-blue-900 dark:bg-blue-950/40">
            <p class="text-sm font-medium text-blue-800 dark:text-blue-200">账户总余额</p><p class="mt-2 text-2xl font-semibold tracking-tight text-blue-700 dark:text-blue-300">¥ {{ $overview['account_total'] }}</p><p class="mt-2 text-xs text-blue-700/70 dark:text-blue-300/70">包含账户间转账</p>
        </a>
    </div>

    @unless ($hasEntries)
        <section class="grid gap-4 rounded-xl border border-primary-200 bg-primary-50 p-5 shadow-sm dark:border-primary-800 dark:bg-primary-950/40 sm:grid-cols-[1fr_auto] sm:items-center" aria-labelledby="empty-overview-heading">
            <div class="grid gap-1">
                <h2 id="empty-overview-heading" class="text-base font-semibold text-gray-900 dark:text-white">这个日期范围还没有收支记录</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">本范围收入、支出与期间结余为 0.00；账户总余额显示当前账户余额，可能不为 0.00。</p>
                @if (collect($overview['accounts'])->isEmpty())
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">还没有账户。</p>
                @endif
            </div>
            @if ($this->canWrite())
                <div class="flex flex-wrap gap-2">
                    <a href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl() }}" class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 dark:focus:ring-offset-gray-900">前往明细记一笔</a>
                    @if (collect($overview['accounts'])->isEmpty())
                        <a href="{{ \App\Filament\Pages\BookkeepingAccounts::getUrl() }}" class="inline-flex items-center justify-center rounded-lg border border-primary-300 bg-white px-4 py-2 text-sm font-semibold text-primary-700 transition hover:bg-primary-100 focus:outline-none focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 dark:border-primary-700 dark:bg-gray-900 dark:text-primary-300 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900">设置账户</a>
                    @endif
                </div>
            @else
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">你可以查看当前范围的收支明细和当前账户余额。</p>
            @endif
        </section>
    @endunless

    <div class="grid gap-6">
        <div class="bookkeeping-overview-trend overview-chart-group min-w-0">
            @livewire(\App\Filament\Widgets\BookkeepingTrendChart::class, ['chartData' => $chartData['trend']], key('trend-'.$this->startDate.'-'.$this->endDate))
            <div class="overview-detail-table mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3 dark:border-gray-800">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">趋势明细</p>
                    <span class="text-xs text-gray-500 dark:text-gray-400">按图表周期汇总</span>
                </div>
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/70 dark:text-gray-400">
                        <tr><th scope="col" class="px-4 py-3 font-semibold">日期</th><th scope="col" class="px-4 py-3 text-right font-semibold">收入</th><th scope="col" class="px-4 py-3 text-right font-semibold">支出</th><th scope="col" class="px-4 py-3 text-right font-semibold">结余</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($chartData['trend']['labels'] as $index => $label)
                            @php
                                $income = $chartData['trend']['datasets'][0]['data'][$index] ?? '0.00';
                                $expense = $chartData['trend']['datasets'][1]['data'][$index] ?? '0.00';
                            @endphp
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-gray-800/60">
                                <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">{{ $label }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-emerald-700 dark:text-emerald-300">¥ {{ $income }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-rose-700 dark:text-rose-300">¥ {{ $expense }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">¥ {{ bcsub($income, $expense, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-5 text-center text-gray-500 dark:text-gray-400">当前期间暂无趋势数据。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="overview-chart-group min-w-0">
            @livewire(\App\Filament\Widgets\BookkeepingAccountBalancesChart::class, ['chartData' => $chartData['accounts']], key('accounts-'.$this->startDate.'-'.$this->endDate))
            <div class="overview-detail-table mt-4 overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <table class="w-full min-w-64 text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800/70 dark:text-gray-400">
                        <tr><th scope="col" class="px-4 py-3 font-semibold">账户</th><th scope="col" class="px-4 py-3 text-right font-semibold">当前余额</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($overview['accounts'] as $account)
                            <tr class="transition hover:bg-primary-50/60 dark:hover:bg-primary-500/5">
                                <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">{{ $account['name'] }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">¥ {{ $account['balance'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-5 text-center text-gray-500 dark:text-gray-400">暂无账户余额可展示。</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <a href="{{ \App\Filament\Pages\BookkeepingAccounts::getUrl() }}" class="flex items-center justify-between border-t border-gray-100 bg-gray-50 px-4 py-3 text-sm font-semibold text-primary-700 transition hover:bg-primary-50 dark:border-gray-800 dark:bg-gray-800/70 dark:text-primary-300 dark:hover:bg-primary-500/10">查看账户设置与余额 <span aria-hidden="true">→</span></a>
            </div>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <div class="overview-chart-group min-w-0">
            @livewire(\App\Filament\Widgets\BookkeepingExpenseCategoriesChart::class, ['chartData' => $chartData['expense']], key('expense-'.$this->startDate.'-'.$this->endDate))
            <div class="overview-detail-table mt-4 overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                <table class="w-full min-w-96 text-left text-sm">
                    <thead class="bg-rose-50/70 text-xs uppercase tracking-wide text-rose-800 dark:bg-rose-500/10 dark:text-rose-200">
                        <tr><th scope="col" class="px-4 py-3 font-semibold">支出分类</th><th scope="col" class="px-4 py-3 text-right font-semibold">金额</th><th scope="col" class="px-4 py-3 text-right font-semibold">占比</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($expenseCategories as $category)
                            <tr class="transition hover:bg-rose-50/60 dark:hover:bg-rose-500/5">
                                <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">
                                    @if ($category['id']) <a href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl(['tableFilters' => [...$filters, 'direction' => ['value' => 'expense'], 'category_id' => ['value' => $category['id']]]]) }}" class="hover:text-rose-700 hover:underline dark:hover:text-rose-300">{{ $category['name'] }}</a> @else {{ $category['name'] }} @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">¥ {{ $category['amount'] }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-600 dark:text-gray-300">{{ $category['percentage'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-5 text-center text-gray-500 dark:text-gray-400">当前期间暂无支出记录。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="overview-chart-group min-w-0">
            @livewire(\App\Filament\Widgets\BookkeepingIncomeCategoriesChart::class, ['chartData' => $chartData['income']], key('income-'.$this->startDate.'-'.$this->endDate))
            <div class="overview-detail-table mt-4 overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                <table class="w-full min-w-80 text-left text-sm">
                    <thead class="bg-emerald-50/70 text-xs uppercase tracking-wide text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200">
                        <tr><th scope="col" class="px-4 py-3 font-semibold">收入分类</th><th scope="col" class="px-4 py-3 text-right font-semibold">金额</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($incomeCategories as $category)
                            <tr class="transition hover:bg-emerald-50/60 dark:hover:bg-emerald-500/5">
                                <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-100">
                                    @if ($category['id']) <a href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl(['tableFilters' => [...$filters, 'direction' => ['value' => 'income'], 'category_id' => ['value' => $category['id']]]]) }}" class="hover:text-emerald-700 hover:underline dark:hover:text-emerald-300">{{ $category['name'] }}</a> @else {{ $category['name'] }} @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums text-gray-950 dark:text-white">¥ {{ $category['amount'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-5 text-center text-gray-500 dark:text-gray-400">当前期间暂无收入记录。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="min-w-0">
        <div class="mb-3 flex items-center justify-between"><h2 class="text-base font-semibold text-gray-900 dark:text-white">最近记录</h2><a href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl(['tableFilters' => $filters]) }}" class="text-sm font-medium text-primary-600 dark:text-primary-400">查看明细</a></div>
        {{ $this->table }}
    </div>
    @endunless
    </div>
</x-filament-panels::page>
