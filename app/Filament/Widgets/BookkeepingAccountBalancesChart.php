<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\View\View;

class BookkeepingAccountBalancesChart extends ChartWidget
{
    public array $chartData = [];

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '360px';

    protected string $color = 'info';

    protected ?string $heading = '账户余额';

    protected ?string $description = '显示启用账户及有收支或转账历史账户的当前余额，余额包含转账。';

    protected function getData(): array
    {
        return $this->chartData;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array|RawJs|null
    {
        return ['indexAxis' => 'y'];
    }

    public function getEmptyState(): View
    {
        return view('filament.widgets.bookkeeping-empty-chart', [
            'message' => '添加账户后，这里会展示每个账户的当前余额。',
        ]);
    }

    public function getChartAssistiveContent(): string
    {
        return collect($this->chartData['labels'] ?? [])->map(fn (string $label, int $index): string => sprintf('%s：%s 元。', $label, $this->chartData['datasets'][0]['data'][$index] ?? '0.00'))->join(' ');
    }
}
