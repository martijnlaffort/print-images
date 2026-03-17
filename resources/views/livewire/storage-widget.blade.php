<div class="mb-6 rounded-xl bg-white border border-gray-200 p-4 shadow-sm">
    <div class="flex items-center justify-between mb-3">
        <div>
            <h3 class="text-sm font-semibold text-gray-900">Storage Usage</h3>
            <p class="text-lg font-bold text-gray-900">{{ $totalFormatted }}</p>
        </div>
        <div class="flex items-center gap-2">
            <button
                wire:click="cleanupThumbnails"
                wire:confirm="Delete all cached thumbnails?"
                wire:loading.attr="disabled"
                wire:target="cleanupThumbnails"
                class="rounded px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-50"
            >
                Clean Thumbnails
            </button>
            <button
                wire:click="recalculate"
                wire:loading.attr="disabled"
                wire:target="recalculate"
                class="rounded px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="recalculate">Refresh</span>
                <span wire:loading wire:target="recalculate">Refreshing...</span>
            </button>
        </div>
    </div>

    {{-- Bar chart --}}
    <div class="h-3 w-full rounded-full bg-gray-100 overflow-hidden flex">
        @foreach($categories as $cat)
            @if($cat['percent'] > 0)
                <div class="{{ $cat['color'] }} h-full" style="width: {{ $cat['percent'] }}%"></div>
            @endif
        @endforeach
    </div>

    {{-- Legend --}}
    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
        @foreach($categories as $cat)
            <div class="flex items-center gap-1.5 text-xs text-gray-600">
                <span class="inline-block h-2 w-2 rounded-full {{ $cat['color'] }}"></span>
                {{ $cat['label'] }}: {{ $cat['formatted'] }}
            </div>
        @endforeach
    </div>
</div>
