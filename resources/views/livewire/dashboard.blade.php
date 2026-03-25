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
                wire:click="$toggle('showTrash')"
                class="relative inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition-colors {{ $showTrash ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' }}"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Trash
                @if($trashedCount > 0)
                    <span class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-xs font-medium text-white min-w-[1.25rem]">{{ $trashedCount }}</span>
                @endif
            </button>
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

    @livewire('storage-widget')

    @if($showTrash)
        {{-- Trash view --}}
        <div class="mb-6">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-semibold text-gray-900">Trash</h2>
                @if($trashedPosters->isNotEmpty())
                    <button
                        wire:click="emptyTrash"
                        wire:confirm="Permanently delete all trashed posters? This cannot be undone."
                        class="rounded bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700"
                    >
                        Empty Trash
                    </button>
                @endif
            </div>

            @if($trashedPosters->isEmpty())
                <p class="text-sm text-gray-500 py-8 text-center">Trash is empty.</p>
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                    @foreach($trashedPosters as $poster)
                        <div class="group relative overflow-hidden rounded-lg bg-white shadow-sm border border-gray-200 opacity-75">
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
                                <p class="text-xs text-gray-400 mt-0.5">Deleted {{ $poster->deleted_at->diffForHumans() }}</p>
                                <div class="mt-2 flex items-center gap-2">
                                    <button
                                        wire:click="restorePoster({{ $poster->id }})"
                                        wire:loading.attr="disabled"
                                        class="rounded bg-indigo-600 px-2 py-1 text-xs font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        Restore
                                    </button>
                                    <button
                                        wire:click="permanentlyDelete({{ $poster->id }})"
                                        wire:confirm="Permanently delete this poster? This cannot be undone."
                                        wire:loading.attr="disabled"
                                        class="rounded bg-red-600 px-2 py-1 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50"
                                    >
                                        Delete Forever
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if($selected && !$showTrash)
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
                wire:confirm="Move selected posters to trash?"
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

    @if($posters->isEmpty() && !$showTrash)
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
    @elseif(!$showTrash)
        {{-- Poster grid --}}
        <div class="mb-3 flex items-center gap-2">
            <label class="flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Select All
            </label>
            <span class="text-sm text-gray-400">{{ $posters->total() }} posters</span>
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

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5" @if($hasActiveJobs) wire:poll.5s @endif>
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

                    <div class="aspect-[3/4] bg-gray-100 overflow-hidden cursor-pointer" wire:click="showDetail({{ $poster->id }})">
                        @if($poster->thumbnail_url)
                            <img src="{{ $poster->thumbnail_url }}" alt="{{ $poster->title }}" class="h-full w-full object-cover">
                        @else
                            <div class="flex h-full items-center justify-center text-gray-400">
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M6.75 7.5h.008v.008H6.75V7.5z" />
                                </svg>
                            </div>
                        @endif

                        {{-- Progress overlay --}}
                        @if(isset($cardProgress[$poster->id]))
                            @php $prog = $cardProgress[$poster->id]; @endphp
                            <div class="absolute inset-0 bg-black/40 flex flex-col items-center justify-center">
                                <div class="text-white text-xs font-medium mb-1">
                                    {{ ucfirst($prog['type']) }}: {{ $prog['stage'] }}
                                </div>
                                <div class="w-3/4 h-1.5 bg-white/30 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full transition-all duration-500 {{ $prog['type'] === 'upscale' ? 'bg-blue-400' : ($prog['type'] === 'mockup' ? 'bg-purple-400' : 'bg-green-400') }}" style="width: {{ $prog['percent'] }}%"></div>
                                </div>
                                <div class="text-white/80 text-xs mt-1">{{ $prog['percent'] }}%</div>
                            </div>
                        @endif
                    </div>

                    <div class="p-3">
                        <p class="truncate text-sm font-medium text-gray-900">{{ $poster->title }}</p>
                        <div class="mt-1 flex items-center justify-between">
                            <div class="flex items-center gap-1">
                                <span @class([
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                    'bg-gray-100 text-gray-700' => $poster->status === 'imported',
                                    'bg-blue-100 text-blue-700' => $poster->status === 'upscaled',
                                    'bg-purple-100 text-purple-700' => $poster->status === 'mockups_ready',
                                    'bg-green-100 text-green-700' => $poster->status === 'exported',
                                ])>
                                    {{ str_replace('_', ' ', $poster->status) }}
                                </span>
                                @if($poster->pushed_at)
                                    <span class="inline-flex items-center gap-0.5 rounded-full bg-emerald-100 text-emerald-700 px-2 py-0.5 text-xs font-medium" title="Pushed {{ $poster->pushed_at->diffForHumans() }}">
                                        <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                        Pushed
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                <button
                                    wire:click="showHistory({{ $poster->id }})"
                                    class="text-gray-400 opacity-0 transition-opacity hover:text-indigo-500 group-hover:opacity-100"
                                    title="View history"
                                >
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </button>
                                <button
                                    wire:click="deletePoster({{ $poster->id }})"
                                    wire:confirm="Move this poster to trash?"
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
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $posters->links() }}
        </div>
    @endif

    {{-- History Modal --}}
    @if($historyPoster)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="closeHistory">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl max-h-[80vh] overflow-y-auto">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900">Activity History</h3>
                    <button wire:click="closeHistory" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                @if($this->history->isEmpty())
                    <p class="text-sm text-gray-500 text-center py-4">No activity recorded yet.</p>
                @else
                    <div class="space-y-3">
                        @foreach($this->history as $activity)
                            <div class="flex items-start gap-3 text-sm">
                                <div class="mt-0.5 flex-shrink-0">
                                    @switch($activity->action)
                                        @case('imported')
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-green-100 text-green-600">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                            </span>
                                            @break
                                        @case('upscaled')
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                                            </span>
                                            @break
                                        @case('mockup_generated')
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-purple-100 text-purple-600">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                            </span>
                                            @break
                                        @case('exported')
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-green-100 text-green-600">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                            </span>
                                            @break
                                        @case('deleted')
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-red-100 text-red-600">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                            </span>
                                            @break
                                        @case('restored')
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                            </span>
                                            @break
                                        @default
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-gray-100 text-gray-600">
                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </span>
                                    @endswitch
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900">{{ str_replace('_', ' ', ucfirst($activity->action)) }}</p>
                                    @if($activity->details)
                                        <p class="text-xs text-gray-500 mt-0.5 truncate">
                                            @foreach($activity->details as $key => $value)
                                                {{ $key }}: {{ is_array($value) ? implode(', ', $value) : $value }}{{ !$loop->last ? ' | ' : '' }}
                                            @endforeach
                                        </p>
                                    @endif
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $activity->created_at->diffForHumans() }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Detail Slide-over Panel --}}
    @if($detail)
        @php $dp = $detail['poster']; @endphp
        <div class="fixed inset-0 z-50 flex justify-end" x-data x-on:keydown.escape.window="$wire.closeDetail()">
            <div class="absolute inset-0 bg-black/50" wire:click="closeDetail"></div>
            <div class="relative w-full max-w-lg bg-white shadow-xl overflow-y-auto">
                {{-- Header --}}
                <div class="sticky top-0 z-10 flex items-center justify-between border-b bg-white px-6 py-4">
                    <h2 class="text-lg font-semibold text-gray-900 truncate">{{ $dp->title }}</h2>
                    <button wire:click="closeDetail" class="text-gray-400 hover:text-gray-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {{-- Image preview --}}
                <div class="bg-gray-50 p-4">
                    <div x-data="{ showUpscaled: false }" class="relative">
                        @if($dp->upscaled_url)
                            <div class="mb-2 flex rounded-lg bg-gray-200 p-0.5 text-xs font-medium w-fit">
                                <button
                                    @click="showUpscaled = false"
                                    :class="!showUpscaled ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                                    class="rounded-md px-3 py-1 transition-colors"
                                >Original</button>
                                <button
                                    @click="showUpscaled = true"
                                    :class="showUpscaled ? 'bg-white shadow text-gray-900' : 'text-gray-500 hover:text-gray-700'"
                                    class="rounded-md px-3 py-1 transition-colors"
                                >Upscaled</button>
                            </div>
                        @endif
                        <img
                            x-show="!showUpscaled"
                            src="{{ $dp->original_url }}"
                            alt="{{ $dp->title }}"
                            class="w-full rounded-lg shadow-sm"
                        >
                        @if($dp->upscaled_url)
                            <img
                                x-show="showUpscaled"
                                src="{{ $dp->upscaled_url }}"
                                alt="{{ $dp->title }} (upscaled)"
                                class="w-full rounded-lg shadow-sm"
                            >
                        @endif
                    </div>
                </div>

                {{-- File info --}}
                <div class="border-b px-6 py-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">File Information</h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Status</p>
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium mt-0.5',
                                'bg-gray-100 text-gray-700' => $dp->status === 'imported',
                                'bg-blue-100 text-blue-700' => $dp->status === 'upscaled',
                                'bg-purple-100 text-purple-700' => $dp->status === 'mockups_ready',
                                'bg-green-100 text-green-700' => $dp->status === 'exported',
                            ])>{{ str_replace('_', ' ', $dp->status) }}</span>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 uppercase tracking-wide">Imported</p>
                            <p class="text-gray-900 mt-0.5">{{ $dp->created_at->format('M j, Y H:i') }}</p>
                        </div>

                        @if(isset($detail['fileInfo']['original']))
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Original Size</p>
                                <p class="text-gray-900 mt-0.5">{{ $detail['fileInfo']['original']['size'] }}</p>
                            </div>
                            @if($detail['fileInfo']['original']['width'])
                                <div>
                                    <p class="text-xs text-gray-500 uppercase tracking-wide">Original Dimensions</p>
                                    <p class="text-gray-900 mt-0.5">{{ $detail['fileInfo']['original']['width'] }} x {{ $detail['fileInfo']['original']['height'] }} px</p>
                                </div>
                            @endif
                        @endif

                        @if(isset($detail['fileInfo']['upscaled']))
                            <div>
                                <p class="text-xs text-gray-500 uppercase tracking-wide">Upscaled Size</p>
                                <p class="text-gray-900 mt-0.5">{{ $detail['fileInfo']['upscaled']['size'] }}</p>
                            </div>
                            @if($detail['fileInfo']['upscaled']['width'])
                                <div>
                                    <p class="text-xs text-gray-500 uppercase tracking-wide">Upscaled Dimensions</p>
                                    <p class="text-gray-900 mt-0.5">{{ $detail['fileInfo']['upscaled']['width'] }} x {{ $detail['fileInfo']['upscaled']['height'] }} px</p>
                                </div>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- Push to Webshop --}}
                <div class="border-b px-6 py-4">
                    @if($dp->pushed_at)
                        <div class="flex items-center gap-2 rounded-lg bg-emerald-50 border border-emerald-200 px-4 py-2.5 mb-3">
                            <svg class="h-4 w-4 text-emerald-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <span class="text-sm text-emerald-700">Pushed {{ $dp->pushed_at->diffForHumans() }}</span>
                        </div>
                    @endif
                    <button
                        wire:click="pushToShop({{ $dp->id }})"
                        wire:loading.attr="disabled"
                        wire:target="pushToShop"
                        @if($dp->pushed_at) wire:confirm="This poster was already pushed {{ $dp->pushed_at->diffForHumans() }}. Push again?" @endif
                        class="w-full inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium text-white disabled:opacity-50 transition-colors {{ $dp->pushed_at ? 'bg-gray-500 hover:bg-gray-600' : 'bg-emerald-600 hover:bg-emerald-700' }}"
                    >
                        <svg wire:loading.remove wire:target="pushToShop" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <x-spinner wire:loading wire:target="pushToShop" class="h-4 w-4" />
                        <span wire:loading.remove wire:target="pushToShop">{{ $dp->pushed_at ? 'Push Again' : 'Push to Webshop' }}</span>
                        <span wire:loading wire:target="pushToShop">Pushing...</span>
                    </button>
                </div>

                {{-- Generated Mockups --}}
                @if($detail['mockups']->isNotEmpty())
                    <div class="border-b px-6 py-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-3">Mockups ({{ $detail['mockups']->count() }})</h3>
                        <div class="grid grid-cols-3 gap-2">
                            @foreach($detail['mockups'] as $mockup)
                                <a href="{{ route('mockup.image', $mockup) }}" target="_blank" class="block">
                                    <img
                                        src="{{ route('mockup.image', $mockup) }}"
                                        alt="Mockup"
                                        class="w-full rounded border border-gray-200 hover:border-indigo-400 transition-colors"
                                    >
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Activity Timeline --}}
                <div class="px-6 py-4">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Activity</h3>
                    @if($detail['activities']->isEmpty())
                        <p class="text-sm text-gray-500">No activity recorded.</p>
                    @else
                        <div class="space-y-2.5">
                            @foreach($detail['activities'] as $activity)
                                <div class="flex items-start gap-2.5 text-sm">
                                    <div class="mt-0.5 flex-shrink-0">
                                        @switch($activity->action)
                                            @case('imported')
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-green-100 text-green-600">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                                </span>
                                                @break
                                            @case('upscaled')
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" /></svg>
                                                </span>
                                                @break
                                            @case('mockup_generated')
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-purple-100 text-purple-600">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                                </span>
                                                @break
                                            @case('exported')
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-green-100 text-green-600">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                                </span>
                                                @break
                                            @case('deleted')
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-red-100 text-red-600">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                                </span>
                                                @break
                                            @case('restored')
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                                </span>
                                                @break
                                            @default
                                                <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-gray-100 text-gray-600">
                                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                </span>
                                        @endswitch
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <p class="font-medium text-gray-900">{{ str_replace('_', ' ', ucfirst($activity->action)) }}</p>
                                            <p class="text-xs text-gray-400 flex-shrink-0">{{ $activity->created_at->diffForHumans() }}</p>
                                        </div>
                                        @if($activity->details)
                                            <p class="text-xs text-gray-500 mt-0.5">
                                                @foreach($activity->details as $key => $value)
                                                    {{ $key }}: {{ is_array($value) ? implode(', ', $value) : $value }}{{ !$loop->last ? ' | ' : '' }}
                                                @endforeach
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
