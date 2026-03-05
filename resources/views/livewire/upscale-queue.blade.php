<div @if($processing) wire:poll.3s="checkJobStatus" @endif>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Upscale Queue</h1>
    </div>

    @if($processing)
        <div class="mb-6 flex items-center gap-3 rounded-lg bg-indigo-50 border border-indigo-200 px-4 py-3">
            <x-spinner class="h-5 w-5 text-indigo-600" />
            <span class="text-sm font-medium text-indigo-700">Upscaling in progress...</span>
        </div>
    @endif

    @if(!$binaryAvailable)
        <div class="mb-6 rounded-lg bg-amber-50 border border-amber-200 p-4">
            <div class="flex items-start gap-3">
                <svg class="h-5 w-5 text-amber-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                </svg>
                <div>
                    <p class="font-medium text-amber-800">Real-ESRGAN binary not found</p>
                    <p class="mt-1 text-sm text-amber-700">
                        Download <code class="rounded bg-amber-100 px-1">realesrgan-ncnn-vulkan.exe</code> from
                        <a href="https://github.com/xinntao/Real-ESRGAN-ncnn-vulkan/releases" target="_blank" class="underline">GitHub Releases</a>
                        and place it in <code class="rounded bg-amber-100 px-1">bin/win/</code>
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- Enhancement Presets --}}
    <div class="mb-4">
        <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Enhancement Presets</h2>
        <div class="flex gap-2">
            <button wire:click="applyPreset('standard')" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Standard</button>
            <button wire:click="applyPreset('detailed')" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Detailed</button>
            <button wire:click="applyPreset('sharp')" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Sharp</button>
            <button wire:click="applyPreset('vivid')" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Vivid</button>
            <button wire:click="applyPreset('gentle')" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Gentle</button>
        </div>
    </div>

    {{-- Controls --}}
    <div class="mb-6 rounded-lg bg-white p-5 shadow-sm border border-gray-200">
        <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-4">Upscale Settings</h2>
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Target Print Size</label>
                <select wire:model.live="targetSize" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach($printSizes as $name => $spec)
                        <option value="{{ $name }}">{{ $name }} ({{ $spec['width_cm'] }} x {{ $spec['height_cm'] }} cm)</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Target DPI</label>
                <select wire:model.live="targetDpi" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="250">250 DPI (Good)</option>
                    <option value="300">300 DPI (Recommended)</option>
                    <option value="350">350 DPI (High Quality)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">AI Model</label>
                <select wire:model="model" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @foreach($models as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Denoise: {{ $denoise }}%</label>
                <input type="range" wire:model.live="denoise" min="0" max="100" step="10"
                    class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                <div class="flex justify-between text-xs text-gray-400 mt-0.5">
                    <span>Sharp</span>
                    <span>Smooth</span>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">GPU Tile Size</label>
                <select wire:model="tileSize" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <option value="0">Auto (default)</option>
                    <option value="400">400 (8GB+ VRAM)</option>
                    <option value="200">200 (6GB VRAM)</option>
                    <option value="100">100 (4GB VRAM)</option>
                </select>
                <p class="text-xs text-gray-400 mt-0.5">Lower = less VRAM usage</p>
            </div>
        </div>

        {{-- Row 2: Advanced settings (collapsible) --}}
        <details class="mt-4">
            <summary class="text-xs font-medium text-gray-500 cursor-pointer hover:text-gray-700">
                Advanced Settings
                @if($sharpen > 0 || $brightness !== 100 || $contrast !== 0 || $saturation !== 100)
                    <span class="ml-1 inline-flex items-center rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700">Modified</span>
                @endif
            </summary>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mt-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sharpen: {{ $sharpen }}%</label>
                    <input type="range" wire:model.live="sharpen" min="0" max="100" step="10"
                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                    <div class="flex justify-between text-xs text-gray-400 mt-0.5">
                        <span>Off</span>
                        <span>Strong</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Brightness: {{ $brightness }}%</label>
                    <input type="range" wire:model.live="brightness" min="50" max="150" step="5"
                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                    <div class="flex justify-between text-xs text-gray-400 mt-0.5">
                        <span>Darker</span>
                        <span>Brighter</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Contrast: {{ $contrast }}</label>
                    <input type="range" wire:model.live="contrast" min="-50" max="50" step="5"
                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                    <div class="flex justify-between text-xs text-gray-400 mt-0.5">
                        <span>Less</span>
                        <span>More</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Saturation: {{ $saturation }}%</label>
                    <input type="range" wire:model.live="saturation" min="50" max="150" step="5"
                        class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
                    <div class="flex justify-between text-xs text-gray-400 mt-0.5">
                        <span>Muted</span>
                        <span>Vivid</span>
                    </div>
                </div>
            </div>
        </details>

        {{-- Action buttons --}}
        <div class="mt-4 flex items-center gap-3">
            @if(count($selected) > 0)
                <button
                    wire:click="startUpscale"
                    wire:loading.attr="disabled"
                    wire:target="startUpscale"
                    @disabled(!$binaryAvailable)
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <x-spinner wire:loading wire:target="startUpscale" />
                    <span wire:loading.remove wire:target="startUpscale">Upscale Selected ({{ count($selected) }})</span>
                    <span wire:loading wire:target="startUpscale">Queuing...</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Before/After Comparison --}}
    @if($comparePoster)
        @php $cp = \App\Models\Poster::find($comparePoster); @endphp
        @if($cp && $cp->upscaled_url && $cp->original_url)
            <div class="mb-6 rounded-lg bg-white border border-gray-200 p-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-gray-700">Before / After: {{ $cp->title }}</h2>
                    <button wire:click="toggleCompare({{ $cp->id }})" class="text-xs text-gray-400 hover:text-gray-600">Close</button>
                </div>
                <div
                    x-data="{
                        sliderPos: 50,
                        dragging: false,
                        onMove(e) {
                            if (!this.dragging) return;
                            const rect = this.$refs.container.getBoundingClientRect();
                            this.sliderPos = Math.max(0, Math.min(100, ((e.clientX - rect.left) / rect.width) * 100));
                        }
                    }"
                    class="relative select-none cursor-col-resize overflow-hidden rounded-lg"
                    x-ref="container"
                    @mousedown="dragging = true"
                    @mousemove="onMove($event)"
                    @mouseup.window="dragging = false"
                >
                    {{-- After (upscaled) - full width --}}
                    <img src="{{ $cp->upscaled_url }}" class="w-full block" draggable="false">
                    {{-- Before (original) - clipped --}}
                    <div class="absolute inset-0 overflow-hidden" :style="`width: ${sliderPos}%`">
                        <img src="{{ $cp->original_url }}" class="w-full block" style="min-width: 100vw; max-width: none; width: var(--container-width);" draggable="false"
                            x-init="$el.style.width = $refs.container.offsetWidth + 'px'">
                    </div>
                    {{-- Slider line --}}
                    <div class="absolute top-0 bottom-0 w-0.5 bg-white shadow-lg z-10" :style="`left: ${sliderPos}%`">
                        <div class="absolute top-1/2 -translate-x-1/2 -translate-y-1/2 h-8 w-8 rounded-full bg-white shadow-lg flex items-center justify-center">
                            <svg class="h-4 w-4 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4" /></svg>
                        </div>
                    </div>
                    {{-- Labels --}}
                    <div class="absolute top-2 left-2 rounded bg-black/50 px-2 py-0.5 text-xs text-white">Original</div>
                    <div class="absolute top-2 right-2 rounded bg-black/50 px-2 py-0.5 text-xs text-white">Upscaled</div>
                </div>
            </div>
        @endif
    @endif

    {{-- Poster list --}}
    <div class="mb-3">
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            Select all
        </label>
    </div>

    <div class="space-y-2">
        @forelse($posters as $poster)
            @php
                $info = $dpiInfo[$poster->id] ?? null;
                $prog = $progress[$poster->id] ?? null;
            @endphp
            <div class="flex items-center gap-4 rounded-lg bg-white p-4 shadow-sm border border-gray-200">
                <input
                    type="checkbox"
                    value="{{ $poster->id }}"
                    wire:model.live="selected"
                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                >

                <div class="h-12 w-12 shrink-0 overflow-hidden rounded bg-gray-100">
                    @if($poster->thumbnail_url)
                        <img src="{{ $poster->thumbnail_url }}" class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-900">{{ $poster->title }}</p>
                    <p class="text-xs text-gray-500">{{ basename($poster->original_path) }}</p>
                    {{-- Progress bar --}}
                    @if($prog && $prog['percent'] < 100)
                        <div class="mt-1 flex items-center gap-2">
                            <div class="flex-1 h-1.5 bg-gray-200 rounded-full overflow-hidden">
                                <div class="h-full bg-indigo-500 rounded-full transition-all" style="width: {{ $prog['percent'] }}%"></div>
                            </div>
                            <span class="text-[10px] text-indigo-600 font-medium">{{ ucfirst($prog['stage']) }} {{ $prog['percent'] }}%</span>
                        </div>
                    @endif
                </div>

                {{-- DPI Info --}}
                @if($info)
                    <div class="flex items-center gap-3 text-xs">
                        <div class="text-gray-500">
                            {{ $info['current_width'] }} x {{ $info['current_height'] }}px
                        </div>
                        <div @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium',
                            'bg-green-100 text-green-700' => $info['meets_target'],
                            'bg-yellow-100 text-yellow-700' => !$info['meets_target'] && $info['meets_minimum'],
                            'bg-red-100 text-red-700' => !$info['meets_minimum'],
                        ])>
                            @if($info['meets_target'])
                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                            @endif
                            {{ $info['current_dpi'] }} DPI
                        </div>
                        @if($info['needs_upscale'])
                            <div class="text-gray-400">
                                &rarr; {{ $info['scale_needed'] }}x needed
                            </div>
                        @else
                            <div class="text-green-600 font-medium">
                                Already sufficient
                            </div>
                        @endif
                    </div>
                @endif

                <span @class([
                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                    'bg-gray-100 text-gray-700' => $poster->status === 'imported',
                    'bg-blue-100 text-blue-700' => $poster->status === 'upscaled',
                    'bg-purple-100 text-purple-700' => $poster->status === 'mockups_ready',
                    'bg-green-100 text-green-700' => $poster->status === 'exported',
                ])>
                    {{ str_replace('_', ' ', $poster->status) }}
                </span>

                <div class="flex gap-1">
                    @if($binaryAvailable)
                        <button
                            wire:click="upscaleSingle({{ $poster->id }})"
                            wire:loading.attr="disabled"
                            wire:target="upscaleSingle({{ $poster->id }})"
                            class="inline-flex items-center gap-1.5 rounded bg-indigo-100 px-3 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-200 disabled:opacity-50"
                        >
                            <x-spinner wire:loading wire:target="upscaleSingle({{ $poster->id }})" class="h-3 w-3" />
                            <span wire:loading.remove wire:target="upscaleSingle({{ $poster->id }})">
                                {{ $poster->status === 'imported' ? 'Upscale' : 'Re-upscale' }}
                            </span>
                            <span wire:loading wire:target="upscaleSingle({{ $poster->id }})">Queuing...</span>
                        </button>
                    @endif

                    @if($poster->upscaled_path)
                        <button
                            wire:click="toggleCompare({{ $poster->id }})"
                            class="inline-flex items-center gap-1.5 rounded bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200"
                        >
                            @if($comparePoster === $poster->id) Hide @else Compare @endif
                        </button>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-lg bg-white p-8 text-center shadow-sm border border-gray-200">
                <p class="text-gray-500">No posters imported yet. Go to the <a href="/" class="text-indigo-600 underline" wire:navigate>Dashboard</a> to import images.</p>
            </div>
        @endforelse
    </div>
</div>
