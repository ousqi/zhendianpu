<x-filament-panels::page>
    @php($summary = $this->summary())
    <div class="grid gap-3 md:grid-cols-3">
        <div class="rounded-xl bg-emerald-50 p-4 dark:bg-emerald-950"><p class="text-sm text-gray-600 dark:text-gray-300">当前筛选收入</p><p class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-300">{{ $this->ledger()->currency }} {{ $summary['income'] }}</p></div>
        <div class="rounded-xl bg-rose-50 p-4 dark:bg-rose-950"><p class="text-sm text-gray-600 dark:text-gray-300">当前筛选支出</p><p class="mt-1 text-xl font-semibold text-rose-700 dark:text-rose-300">{{ $this->ledger()->currency }} {{ $summary['expense'] }}</p></div>
        <div class="rounded-xl bg-slate-100 p-4 dark:bg-slate-800"><p class="text-sm text-gray-600 dark:text-gray-300">当前筛选结余</p><p class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">{{ $this->ledger()->currency }} {{ $summary['balance'] }}</p></div>
    </div>
    {{ $this->table }}
</x-filament-panels::page>
