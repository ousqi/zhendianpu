<div class="grid min-h-52 place-items-center rounded-lg border border-dashed border-gray-300 bg-gray-50/70 p-6 text-center dark:border-gray-600 dark:bg-gray-800/50" role="status">
    <div class="grid max-w-xs gap-3">
        <div class="mx-auto grid h-16 w-28 grid-cols-4 items-end gap-2 rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900" aria-hidden="true">
            @foreach ([4, 8, 5, 11] as $height)
                <span class="rounded-sm bg-gray-200 dark:bg-gray-700" style="height: {{ $height * 4 }}%"></span>
            @endforeach
        </div>
        <div class="grid gap-1">
            <p class="font-medium text-gray-900 dark:text-white">暂无数据</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $message }}</p>
        </div>
        <p class="text-xs text-gray-400 dark:text-gray-500">图例和数据将在产生真实记录后显示。</p>
    </div>
</div>
