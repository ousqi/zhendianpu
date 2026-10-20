<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\View\View;

class BookkeepingTrendChart extends ChartWidget
{
    public array $chartData = [];

    protected ?string $pollingInterval = null;

    protected string $color = 'primary';

    protected ?string $heading = '收支趋势';

    protected ?string $description = '按所选日期范围汇总收入和支出，未发生收支的期间以零显示。';

    public function getDescription(): string
    {
        if ($this->hasOnlyZeroValues()) {
            return '暂无实际收支，图表显示零值基线；收入和支出均为 0.00，未伪造金额。';
        }

        return $this->description;
    }

    protected function getData(): array
    {
        return $this->chartData;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true]],
            'scales' => [
                'x' => ['display' => true],
                'y' => ['beginAtZero' => true, 'display' => true],
            ],
        ];
    }

    public function getEmptyState(): View
    {
        return view('filament.widgets.bookkeeping-empty-chart', [
            'message' => '所选日期范围内还没有有效的收支记录。',
        ]);
    }

    public function getChartAssistiveContent(): string
    {
        return collect($this->chartData['labels'] ?? [])->map(function (string $label, int $index): string {
            return sprintf('%s：收入 %s 元，支出 %s 元。', $label, $this->chartData['datasets'][0]['data'][$index] ?? '0.00', $this->chartData['datasets'][1]['data'][$index] ?? '0.00');
        })->join(' ');
    }

    private function hasOnlyZeroValues(): bool
    {
        $datasets = $this->chartData['datasets'] ?? [];

        return $datasets !== [] && collect($datasets)->every(fn (array $dataset): bool => collect($dataset['data'] ?? [])->every(fn (string $value): bool => bccomp($value, '0.00', 2) === 0));
    }
}
