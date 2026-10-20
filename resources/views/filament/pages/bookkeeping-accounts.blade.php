<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->table }}

        @livewire(\App\Filament\Widgets\BookkeepingTransfersTable::class)
    </div>
</x-filament-panels::page>
