<section class="zdp-panel-hero" aria-label="收支记账工作台">
    <div>
        <span class="eyebrow">{{ config('app.shop_name') }} · 记账工作台</span>
        <h2>把每一笔收支，记得清清楚楚。</h2>
        <p>你好，{{ auth()->user()->name }}。记录今天已经发生的收入和支出，随时查看期间结余。</p>
        <nav class="actions" aria-label="快捷记账">
            <a class="badge" href="{{ \App\Filament\Pages\BookkeepingEntries::getUrl() }}">记一笔</a>
            <a class="badge" href="{{ \App\Filament\Pages\BookkeepingOverview::getUrl() }}">收支概览</a>
        </nav>
    </div>
    <span class="zdp-panel-mark" aria-hidden="true">点</span>
</section>
