<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
        <div class="flex items-center gap-3">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search posters..."
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
            <button
                wire:click="importViaDialog"
                wire:loading.attr="disabled"
                wire:target="importViaDialog"
                class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
            >
                <x-spinner wire:loading wire:target="importViaDialog" />
                <span wire:loading.remove wire:target="importViaDialog">Import Files</span>
                <span wire:loading wire:target="importViaDialog">Importing...</span>
            </button>
        </div>
    </div>

    @if($selected)
        <div class="mb-4 flex items-center gap-3 rounded-lg bg-indigo-50 px-4 py-3">
            <span class="text-sm text-indigo-700 font-medium">{{ count($selected) }} selected</span>
            <a href="/upscale" class="rounded bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-700" wire:navigate>
                Upscale Selected
            </a>
            <a href="/mockups" class="rounded bg-purple-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-purple-700" wire:navigate>
                Generate Mockups
            </a>
            <a href="/export" class="rounded bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700" wire:navigate>
                Export
            </a>
            <button
                wire:click="deleteSelected"
                wire:confirm="Delete selected posters?"
                wire:loading.attr="disabled"
                wire:target="deleteSelected"
                class="inline-flex items-center gap-1.5 rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 ml-auto disabled:opacity-50"
            >
                <x-spinner wire:loading wire:target="deleteSelected" class="h-3 w-3" />
                <span wire:loading.remove wire:target="deleteSelected">Delete Selected</span>
                <span wire:loading wire:target="deleteSelected">Deleting...</span>
            </button>
        </div>
    @endif

    @if($posters->isEmpty())
        {{-- Empty state with drag-and-drop --}}
        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave="dragging = false"
            x-on:drop.prevent="
                dragging = false;
                const files = $event.dataTransfer.files;
                if (files.length) {
                    $wire.upload('photos', files[0], () => {}, () => {}, (event) => {});
                    for (let i = 0; i < files.length; i++) {
                        $wire.upload('photos', files[i]);
                    }
                }
            "
            class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed p-16 transition-colors"
            :class="dragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300 bg-white'"
        >
            <svg class="mx-auto h-16 w-16 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
            </svg>
            <p class="mt-4 text-lg font-medium text-gray-900">Drop poster images here</p>
            <p class="mt-1 text-sm text-gray-500">or use the Import button above</p>
            <p class="mt-1 text-xs text-gray-400">Supports JPG, PNG, WebP</p>

            <div wire:loading wire:target="photos" class="mt-4 inline-flex items-center gap-2 text-sm text-indigo-600">
                <x-spinner class="h-4 w-4" /> Uploading...
            </div>

            <label wire:loading.remove wire:target="photos" class="mt-6 cursor-pointer rounded-lg bg-white border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Browse Files
                <input type="file" wire:model="photos" multiple accept="image/*" class="hidden">
            </label>
        </div>
    @else
        {{-- Poster grid --}}
        <div class="mb-3 flex items-center gap-2">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Select All
            </label>
            <span class="text-sm text-gray-400">{{ $posters->count() }} posters</span>
        </div>

        {{-- Collapsible upload zone --}}
        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave="dragging = false"
            x-on:drop.prevent="
                dragging = false;
                const files = $event.dataTransfer.files;
                for (let i = 0; i < files.length; i++) {
                    $wire.upload('photos', files[i]);
                }
            "
            class="mb-6 rounded-lg border-2 border-dashed p-4 text-center transition-colors"
            :class="dragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-200 bg-gray-50'"
        >
            <div wire:loading wire:target="photos" class="inline-flex items-center gap-2 text-sm text-indigo-600">
                <x-spinner class="h-4 w-4" /> Uploading...
            </div>
            <label wire:loading.remove wire:target="photos" class="cursor-pointer text-sm text-gray-500 hover:text-indigo-600">
                Drop more images here or <span class="font-medium text-indigo-600 underline">browse</span>
                <input type="file" wire:model="photos" multiple accept="image/*" class="hidden">
            </label>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
            @foreach($posters as $poster)
                <div
                    class="group relative overflow-hidden rounded-lg bg-white shadow-sm border transition-all hover:shadow-md"
                    :class="$wire.selected.includes('{{ $poster->id }}') ? 'ring-2 ring-indigo-500 border-indigo-500' : 'border-gray-200'"
                >
                    <div class="absolute top-2 left-2 z-10">
                        <input
                            type="checkbox"
                            value="{{ $poster->id }}"
                            wire:model.live="selected"
                            class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                        >
                    </div>

                    <div class="aspect-[3/4] bg-gray-100 overflow-hidden">
                        @if($poster->thumbnail_url)
                            <img src="{{ $poster->thumbnail_url }}" alt="{{ $poster->title }}" class="h-full w-full object-cover">
                        @else
                            <div class="flex h-full items-center justify-center text-gray-400">
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M6.75 7.5h.008v.008H6.75V7.5z" />
                                </svg>
                            </div>
                        @endif
                    </div>

                    <div class="p-3">
                        <p class="truncate text-sm font-medium text-gray-900">{{ $poster->title }}</p>
                        <div class="mt-1 flex items-center justify-between">
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-gray-100 text-gray-700' => $poster->status === 'imported',
                                'bg-blue-100 text-blue-700' => $poster->status === 'upscaled',
                                'bg-purple-100 text-purple-700' => $poster->status === 'mockups_ready',
                                'bg-green-100 text-green-700' => $poster->status === 'exported',
                            ])>
                                {{ str_replace('_', ' ', $poster->status) }}
                            </span>
                            <button
                                wire:click="deletePoster({{ $poster->id }})"
                                wire:confirm="Delete this poster?"
                                wire:loading.attr="disabled"
                                wire:target="deletePoster({{ $poster->id }})"
                                class="text-gray-400 opacity-0 transition-opacity hover:text-red-500 group-hover:opacity-100 disabled:opacity-50"
                            >
                                <x-spinner wire:loading wire:target="deletePoster({{ $poster->id }})" class="h-4 w-4" />
                                <svg wire:loading.remove wire:target="deletePoster({{ $poster->id }})" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
