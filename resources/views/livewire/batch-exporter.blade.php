<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Export</h1>
        @if(count($selectedPosters) > 0)
            <button
                wire:click="downloadZip"
                wire:loading.attr="disabled"
                wire:target="downloadZip"
                class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
            >
                <x-spinner wire:loading wire:target="downloadZip" />
                <span wire:loading.remove wire:target="downloadZip">Download ZIP</span>
                <span wire:loading wire:target="downloadZip">Creating...</span>
            </button>
        @endif
    </div>

    <div class="grid grid-cols-3 gap-6">
        {{-- Left: Poster selector --}}
        <div>
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Select Posters</h2>
            <div class="space-y-2 max-h-[calc(100vh-250px)] overflow-y-auto">
                @forelse($posters as $poster)
                    <label class="flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors hover:bg-gray-50 {{ in_array($poster->id, $selectedPosters) ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-white' }}">
                        <input
                            type="checkbox"
                            value="{{ $poster->id }}"
                            wire:model.live="selectedPosters"
                            class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
                        >
                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded bg-gray-100">
                            @if($poster->thumbnail_url)
                                <img src="{{ $poster->thumbnail_url }}" class="h-full w-full object-cover">
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $poster->title }}</p>
                        </div>
                    </label>
                @empty
                    <p class="text-sm text-gray-500 p-3">No posters ready for export. <a href="/upscale" class="text-indigo-600 underline" wire:navigate>Upscale some first.</a></p>
                @endforelse
            </div>
        </div>

        {{-- Center: Size selection & DPI validation --}}
        <div>
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Print Sizes</h2>
            <div class="rounded-lg bg-white border border-gray-200 p-4 space-y-3">
                @foreach($availableSizes as $size => $dims)
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input
                            type="checkbox"
                            value="{{ $size }}"
                            wire:model.live="selectedSizes"
                            class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
                        >
                        <span class="text-sm font-medium text-gray-900">{{ $size }}</span>
                        <span class="text-xs text-gray-500">
                            {{ $dims['width_cm'] }} x {{ $dims['height_cm'] }} cm
                        </span>
                    </label>
                @endforeach

                @if(count($selectedPosters) > 0)
                    <button
                        wire:click="validateDpi"
                        wire:loading.attr="disabled"
                        wire:target="validateDpi"
                        class="mt-3 w-full inline-flex items-center justify-center gap-1.5 rounded bg-gray-100 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50"
                    >
                        <x-spinner wire:loading wire:target="validateDpi" class="h-3 w-3" />
                        <span wire:loading.remove wire:target="validateDpi">Check DPI</span>
                        <span wire:loading wire:target="validateDpi">Checking...</span>
                    </button>
                @endif
            </div>

            {{-- DPI results --}}
            @if(!empty($dpiResults))
                <h3 class="mt-4 mb-2 text-sm font-semibold text-gray-700">DPI Validation</h3>
                <div class="space-y-2">
                    @foreach($dpiResults as $posterId => $sizes)
                        @php $poster = \App\Models\Poster::find($posterId); @endphp
                        @if($poster)
                            <div class="rounded-lg bg-white border border-gray-200 p-3">
                                <p class="text-sm font-medium text-gray-900 mb-2">{{ $poster->title }}</p>
                                <div class="space-y-1">
                                    @foreach($sizes as $sizeName => $result)
                                        @if(in_array($sizeName, $selectedSizes))
                                            <div class="flex items-center justify-between text-xs">
                                                <span>{{ $sizeName }}</span>
                                                <span @class([
                                                    'font-medium',
                                                    'text-green-600' => $result['meets_recommended'],
                                                    'text-amber-600' => $result['meets_minimum'] && !$result['meets_recommended'],
                                                    'text-red-600' => !$result['meets_minimum'],
                                                ])>
                                                    {{ $result['effective_dpi'] }} DPI
                                                    @if($result['meets_recommended'])
                                                        &#10003;
                                                    @elseif($result['meets_minimum'])
                                                        ~
                                                    @else
                                                        &#10007;
                                                    @endif
                                                </span>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Right: Export settings --}}
        <div>
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Export Settings</h2>
            <div class="rounded-lg bg-white border border-gray-200 p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Output Format</label>
                    <select wire:model="outputFormat" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                        <option value="png">PNG (lossless)</option>
                        <option value="jpg">JPEG (smaller files)</option>
                    </select>
                </div>

                @if($outputFormat === 'jpg')
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">JPEG Quality: {{ $outputQuality }}%</label>
                        <input type="range" wire:model.live="outputQuality" min="60" max="100" step="1"
                            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-green-600">
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Naming Pattern</label>
                    <input type="text" wire:model="namingPattern" class="w-full rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                    <p class="mt-1 text-xs text-gray-500">Tokens: {title}, {size}, {date}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Output Folder</label>
                    <div class="flex gap-2">
                        <input type="text" wire:model="outputDir" class="flex-1 rounded-lg border-gray-300 text-sm focus:border-green-500 focus:ring-green-500">
                        <button
                            type="button"
                            wire:click="selectOutputDir"
                            wire:loading.attr="disabled"
                            wire:target="selectOutputDir"
                            class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50"
                        >
                            <x-spinner wire:loading wire:target="selectOutputDir" class="h-3 w-3" />
                            Browse
                        </button>
                    </div>
                </div>

                <button
                    wire:click="exportAll"
                    wire:loading.attr="disabled"
                    wire:target="exportAll"
                    @disabled(empty($selectedPosters) || empty($selectedSizes))
                    class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <x-spinner wire:loading wire:target="exportAll" />
                    <span wire:loading.remove wire:target="exportAll">Export All</span>
                    <span wire:loading wire:target="exportAll">Exporting...</span>
                </button>
            </div>
        </div>
    </div>
</div>
