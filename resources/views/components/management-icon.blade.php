@props(['name' => 'grid'])
@php
    $paths = [
        'grid' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
        'report' => 'M4 20h16 M7 16V9 M12 16V4 M17 16v-5',
        'bag' => 'M5 7h14l1 14H4L5 7z M9 7V5a3 3 0 0 1 6 0v2',
        'sales' => 'M3 17l6-6 4 4 8-10 M15 5h6v6',
        'cost' => 'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z M8 7h8 M8 12h2 M14 12h2 M8 16h2 M14 16h2',
        'cash' => 'M3 7h18v13H3z M3 7V4h15 M16 12h5v4h-5z',
        'catalog' => 'M5 3h14v18H5z M9 7h6 M9 11h6 M9 15h4',
        'arrow' => 'M5 12h14 M13 6l6 6-6 6',
        'phone' => 'M8 2h8a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z M10 18h4',
        'menu' => 'M4 6h16 M4 12h16 M4 18h16',
        'shield' => 'M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3z M8 12l3 3 5-6',
        'clock' => 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z M12 7v5l3 2',
    ];
@endphp
<svg {{ $attributes->class(['icon']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['grid'] }}" /></svg>
