<div @if($processing) wire:poll.3s="checkPipelineStatus" @endif>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Quick Pipeline</h1>
            <p class="mt-1 text-sm text-gray-500">Import, upscale, generate mockups, and export in one go.</p>
        </div>
    </div>

    {{-- Pipeline Progress --}}
    @if($processing && $pipelineProgress)
        <div class="mb-6 rounded-lg bg-indigo-50 border border-indigo-200 p-4">
            <div class="flex items-center gap-3 mb-2">
                <svg class="h-5 w-5 animate-spin text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-sm font-medium text-indigo-700">
                    {{ $pipelineProgress['stage'] ?? 'Starting...' }}
                </span>
            </div>
            <div class="w-full bg-indigo-100 rounded-full h-2">
                <div class="h-2 rounded-full bg-indigo-500 transition-all duration-500" style="width: {{ $pipelineProgress['progress'] }}%"></div>
            </div>
            <p class="mt-1 text-xs text-indigo-600">{{ $pipelineProgress['progress'] }}% complete</p>
        </div>
    @endif

    <div class="space-y-5">
        {{-- Poster Selection --}}
        <div class="rounded-lg bg-white p-5 shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Select Posters</h2>
                <label class="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    Select All ({{ count($selectedPosters) }}/{{ $posters->count() }})
                </label>
            </div>

            @if($posters->isEmpty())
                <p class="text-sm text-gray-500">No posters imported yet. Import images from the Dashboard first.</p>
            @else
                <div class="grid grid-cols-4 sm:grid-cols-6 lg:grid-cols-8 gap-2 max-h-48 overflow-y-auto">
                    @foreach($posters as $poster)
                        <label class="relative cursor-pointer group">
                            <input
                                type="checkbox"
                                wire:model.live="selectedPosters"
                                value="{{ $poster->id }}"
                                class="sr-only peer"
                            >
                            <div class="aspect-[3/4] rounded-md overflow-hidden border-2 border-transparent peer-checked:border-indigo-500 peer-checked:ring-2 peer-checked:ring-indigo-200 transition-all">
                                @if($poster->thumbnail_url)
                                    <img src="{{ $poster->thumbnail_url }}" alt="{{ $poster->title }}" class="w-full h-full object-cover">
                                @else
                                    <div class="w-full h-full bg-gray-100 flex items-center justify-center">
                                        <svg class="h-6 w-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                    </div>
                                @endif
                            </div>
                            <p class="mt-0.5 text-[10px] text-gray-500 truncate text-center">{{ $poster->title }}</p>
                            @if($poster->upscaled_path)
                                <span class="absolute top-0.5 right-0.5 w-2 h-2 bg-green-400 rounded-full" title="Already upscaled"></span>
                            @endif
                        </label>
                    @endforeach
                </div>

                @if($this->upscaledCount > 0 && $enableUpscale)
                    <p class="mt-2 text-xs text-green-600">{{ $this->upscaledCount }} poster(s) already upscaled - will be skipped.</p>
                @endif
            @endif
        </div>

        {{-- Stage 1: Upscale --}}
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 bg-gray-50">
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model.live="enableUpscale" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">1</span>
                        <span class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Upscale</span>
                    </div>
                </label>
                @if(!$binaryAvailable && $enableUpscale)
                    <span class="text-xs text-amber-600 font-medium">Binary not found</span>
                @endif
            </div>

            @if($enableUpscale)
                <div class="p-5 space-y-4">
                    {{-- Presets --}}
                    <div>
                        <label class="block text-xs font-medium text-gray-500 mb-1.5">Preset</label>
                        <div class="flex gap-2">
                            @foreach(['standard', 'detailed', 'sharp', 'vivid', 'gentle'] as $preset)
                                <button
                                    wire:click="applyPreset('{{ $preset }}')"
                                    class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors {{ $upscalePreset === $preset ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50' }}"
                                >
                                    {{ ucfirst($preset) }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Print Size</label>
                            <select wire:model.live="targetSize" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach($printSizes as $name => $spec)
                                    <option value="{{ $name }}">{{ $name }} ({{ $spec['width_cm'] }}x{{ $spec['height_cm'] }}cm)</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">DPI</label>
                            <select wire:model.live="targetDpi" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="250">250 (Good)</option>
                                <option value="300">300 (Recommended)</option>
                                <option value="350">350 (High Quality)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">AI Model</label>
                            <select wire:model.live="model" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach($models as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">GPU Tile Size</label>
                            <select wire:model.live="tileSize" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="0">Auto</option>
                                <option value="400">400 (8GB+ VRAM)</option>
                                <option value="200">200 (4GB VRAM)</option>
                                <option value="100">100 (2GB VRAM)</option>
                            </select>
                        </div>
                    </div>

                    <details class="text-sm">
                        <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700">Advanced Settings</summary>
                        <div class="mt-3 grid grid-cols-2 lg:grid-cols-5 gap-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Denoise ({{ $denoise }}%)</label>
                                <input type="range" wire:model.live="denoise" min="0" max="100" class="w-full accent-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Sharpen ({{ $sharpen }}%)</label>
                                <input type="range" wire:model.live="sharpen" min="0" max="100" class="w-full accent-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Brightness ({{ $brightness }}%)</label>
                                <input type="range" wire:model.live="brightness" min="0" max="200" class="w-full accent-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Contrast ({{ $contrast }})</label>
                                <input type="range" wire:model.live="contrast" min="-100" max="100" class="w-full accent-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Saturation ({{ $saturation }}%)</label>
                                <input type="range" wire:model.live="saturation" min="0" max="200" class="w-full accent-indigo-500">
                            </div>
                        </div>
                    </details>
                </div>
            @endif
        </div>

        {{-- Stage 2: Mockups --}}
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 overflow-hidden">
            <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 bg-gray-50">
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model.live="enableMockups" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-purple-100 text-purple-700 text-xs font-bold">2</span>
                        <span class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Mockups</span>
                    </div>
                </label>
                <span class="text-xs text-gray-500">{{ $templates->count() }} template(s) available</span>
            </div>

            @if($enableMockups)
                <div class="p-5 space-y-4">
                    @if($templates->isEmpty())
                        <p class="text-sm text-amber-600">No templates created yet. Create templates in the Templates section first.</p>
                    @else
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Templates</label>
                                <select wire:model.live="templateSelection" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="all">All templates ({{ $templates->count() }})</option>
                                    @if(!empty($categories))
                                        <option value="category">By category</option>
                                    @endif
                                    <option value="specific">Pick specific</option>
                                </select>
                            </div>

                            @if($templateSelection === 'category')
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Category</label>
                                    <select wire:model.live="categoryFilter" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="">Choose...</option>
                                        @foreach($categories as $cat)
                                            <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif

                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Fit Mode</label>
                                <select wire:model.live="fitMode" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="fill">Fill (crop to fit)</option>
                                    <option value="fit">Fit (letterbox)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Frame</label>
                                <select wire:model.live="framePreset" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="none">None</option>
                                    <option value="thin-black">Thin Black</option>
                                    <option value="thin-white">Thin White</option>
                                    <option value="gallery-white">Gallery White</option>
                                    <option value="oak-wood">Oak Wood</option>
                                    <option value="dark-wood">Dark Wood</option>
                                </select>
                            </div>
                        </div>

                        @if($templateSelection === 'specific')
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1.5">Select Templates</label>
                                <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-2 max-h-40 overflow-y-auto">
                                    @foreach($templates as $template)
                                        <label class="relative cursor-pointer group">
                                            <input
                                                type="checkbox"
                                                wire:model.live="selectedTemplates"
                                                value="{{ $template->id }}"
                                                class="sr-only peer"
                                            >
                                            <div class="aspect-[4/3] rounded-md overflow-hidden border-2 border-transparent peer-checked:border-purple-500 peer-checked:ring-2 peer-checked:ring-purple-200 transition-all">
                                                <img src="{{ route('template.image', ['template' => $template->id, 'thumb' => 1]) }}" alt="{{ $template->name }}" class="w-full h-full object-cover">
                                            </div>
                                            <p class="mt-0.5 text-[10px] text-gray-500 truncate text-center">{{ $template->name }}</p>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <details class="text-sm">
                            <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700">Output Settings</summary>
                            <div class="mt-3 grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Format</label>
                                    <select wire:model.live="mockupFormat" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="jpg">JPEG</option>
                                        <option value="png">PNG</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Quality ({{ $mockupQuality }})</label>
                                    <input type="range" wire:model.live="mockupQuality" min="50" max="100" class="w-full accent-purple-500">
                                </div>
                            </div>
                        </details>
                    @endif
                </div>
            @endif
        </div>

        {{-- Stage 3: Export --}}
        <div class="rounded-lg bg-white shadow-sm border border-gray-200 overflow-hidden">
            <div class="flex items-center px-5 py-3 border-b border-gray-100 bg-gray-50">
                <label class="flex items-center gap-3">
                    <input type="checkbox" wire:model.live="enableExport" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-green-100 text-green-700 text-xs font-bold">3</span>
                        <span class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Export Size Variants</span>
                    </div>
                </label>
            </div>

            @if($enableExport)
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Print Sizes</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach($printSizes as $name => $spec)
                                    <label class="flex items-center gap-1.5">
                                        <input type="checkbox" wire:model.live="exportSizes" value="{{ $name }}" class="rounded border-gray-300 text-green-600 focus:ring-green-500">
                                        <span class="text-sm text-gray-700">{{ $name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1">Output Folder</label>
                            <div class="flex gap-2">
                                <input type="text" wire:model="outputDir" class="flex-1 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" readonly>
                                <button wire:click="selectOutputDir" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                                    Browse
                                </button>
                            </div>
                        </div>
                    </div>

                    <details class="text-sm">
                        <summary class="cursor-pointer text-xs font-medium text-gray-500 hover:text-gray-700">Output Settings</summary>
                        <div class="mt-3 grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Format</label>
                                <select wire:model.live="exportFormat" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="png">PNG</option>
                                    <option value="jpg">JPEG</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1">Quality ({{ $exportQuality }})</label>
                                <input type="range" wire:model.live="exportQuality" min="50" max="100" class="w-full accent-green-500">
                            </div>
                        </div>
                    </details>
                </div>
            @endif
        </div>

        {{-- Summary & Start --}}
        <div class="rounded-lg bg-white p-5 shadow-sm border border-gray-200">
            <div class="flex items-center justify-between">
                <div class="text-sm text-gray-600">
                    <span class="font-medium text-gray-900">{{ count($selectedPosters) }} poster(s)</span>
                    @if($enableUpscale)
                        <span class="mx-1 text-gray-400">&rarr;</span>
                        <span>Upscale</span>
                    @endif
                    @if($enableMockups)
                        <span class="mx-1 text-gray-400">&rarr;</span>
                        <span>
                            @if($templateSelection === 'all')
                                {{ $templates->count() }} mockup template(s)
                            @elseif($templateSelection === 'specific')
                                {{ count($selectedTemplates) }} mockup template(s)
                            @else
                                Mockups ({{ $categoryFilter ?: 'all' }})
                            @endif
                        </span>
                    @endif
                    @if($enableExport)
                        <span class="mx-1 text-gray-400">&rarr;</span>
                        <span>Export {{ count($exportSizes) }} size(s)</span>
                    @endif
                </div>

                <button
                    wire:click="startPipeline"
                    @if($processing || empty($selectedPosters)) disabled @endif
                    class="rounded-lg bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                >
                    @if($processing)
                        Processing...
                    @else
                        Start Pipeline
                    @endif
                </button>
            </div>
        </div>
    </div>
</div>
