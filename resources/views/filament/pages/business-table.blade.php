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
    @endif

    {{ $this->table }}
</x-filament-panels::page>
