<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class BookkeepingExpenseCategoriesChart extends ChartWidget
{
    public array $chartData = [];

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '220px';

    protected string $color = 'danger';

    protected ?string $heading = '支出分类';

    protected ?string $description = '按所选日期范围汇总各支出分类金额和占比，明细入口位于图表下方。';

    public function getDescription(): string
    {
        return $this->isPlaceholder() ? '暂无分类数据。图表为占位轮廓，不计入支出统计。' : $this->description;
    }

    protected function getData(): array
    {
        return $this->chartData;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => true], 'tooltip' => ['enabled' => ! $this->isPlaceholder()]],
        ];
    }

    public function getChartAssistiveContent(): string
    {
        if ($this->isPlaceholder()) {
            return '暂无支出分类数据。图表为占位轮廓，不计入支出统计。';
        }

        return collect($this->chartData['labels'] ?? [])->map(function (string $label, int $index): string {
            return sprintf('%s：%s 元，%s%%。', $label, $this->chartData['datasets'][0]['data'][$index] ?? '0.00', $this->chartData['percentages'][$index] ?? '0.00');
        })->join(' ');
    }

    private function isPlaceholder(): bool
    {
        return ($this->chartData['placeholder'] ?? false) === true;
    }
}
