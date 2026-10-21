<x-filament-panels::page>
    @if ($this instanceof \App\Filament\Pages\BookkeepingCategories)
        <x-filament::tabs label="分类方向">
            <x-filament::tabs.item :active="$activeDirection === 'income'" wire:click="$set('activeDirection', 'income')">
                收入分类
            </x-filament::tabs.item>

            <x-filament::tabs.item :active="$activeDirection === 'expense'" wire:click="$set('activeDirection', 'expense')">
                支出分类
            </x-filament::tabs.item>
        </x-filament::tabs>

        @if ($category = $this->selectedTopLevelCategory())
            <div class="mt-4">
                <x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="clearSelectedTopLevelCategory">
                    返回一级分类（{{ $category->name }}）
                </x-filament::button>
            </div>
        @endif
    @endif

    {{ $this->table }}
</x-filament-panels::page>
