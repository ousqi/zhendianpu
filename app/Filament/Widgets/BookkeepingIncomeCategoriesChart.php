<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class BookkeepingIncomeCategoriesChart extends ChartWidget
{
    public array $chartData = [];

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '220px';

    protected string $color = 'success';

    protected ?string $heading = '收入分类';

    protected ?string $description = '按所选日期范围汇总各收入分类金额，明细入口位于图表下方。';

    public function getDescription(): string
    {
        return $this->isPlaceholder() ? '暂无分类数据。图表显示零值基线，不计入收入统计。' : $this->description;
    }

    protected function getData(): array
    {
        return $this->chartData;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => true], 'tooltip' => ['enabled' => ! $this->isPlaceholder()]],
            'scales' => ['x' => ['beginAtZero' => true, 'suggestedMax' => $this->isPlaceholder() ? 1 : null]],
        ];
    }

    public function getChartAssistiveContent(): string
    {
        if ($this->isPlaceholder()) {
            return '暂无收入分类数据。图表显示零值基线，不计入收入统计。';
        }

        return collect($this->chartData['labels'] ?? [])->map(fn (string $label, int $index): string => sprintf('%s：%s 元。', $label, $this->chartData['datasets'][0]['data'][$index] ?? '0.00'))->join(' ');
    }

    private function isPlaceholder(): bool
    {
        return ($this->chartData['placeholder'] ?? false) === true;
    }
}
