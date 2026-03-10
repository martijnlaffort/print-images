<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Mockup Generator</h1>
        <div class="flex gap-2">
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
            @if(count($selectedPosters) > 0 && $templates->isNotEmpty())
                <button
                    wire:click="generateAll"
                    wire:loading.attr="disabled"
                    wire:target="generateAll"
                    class="inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="generateAll" />
                    <span wire:loading.remove wire:target="generateAll">Generate All ({{ count($selectedPosters) }} x {{ $templates->count() }})</span>
                    <span wire:loading wire:target="generateAll">Generating...</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Generation Options --}}
    <div class="mb-6 rounded-lg bg-white border border-gray-200 p-4">
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Fit Mode</label>
                <select wire:model="fitMode" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <option value="fill">Fill (crop to fit)</option>
                    <option value="fit">Fit (letterbox)</option>
                    <option value="stretch">Stretch</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Frame Style</label>
                <select wire:model="framePreset" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                    @foreach($framePresets as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Format</label>
                <select wire:model="outputFormat" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <option value="jpg">JPEG</option>
                    <option value="png">PNG</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Quality: {{ $outputQuality }}%</label>
                <input type="range" wire:model.live="outputQuality" min="60" max="100" step="1"
                    class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-purple-600">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Category</label>
                <select wire:model.live="categoryFilter" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ $cat }}">{{ ucfirst(str_replace('-', ' ', $cat)) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Text Overlay (collapsible) --}}
        <details class="mt-3">
            <summary class="text-xs font-medium text-gray-500 cursor-pointer hover:text-gray-700">Text Overlay (optional)</summary>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Text</label>
                    <input type="text" wire:model="overlayText" placeholder="e.g. Art by Studio Name" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Font Size</label>
                    <input type="number" wire:model="overlayFontSize" min="12" max="72" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Color</label>
                    <select wire:model="overlayFontColor" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="white">White</option>
                        <option value="black">Black</option>
                        <option value="#333333">Dark Gray</option>
                        <option value="#CCCCCC">Light Gray</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Position</label>
                    <select wire:model="overlayPosition" class="w-full rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500">
                        <option value="South">Bottom</option>
                        <option value="North">Top</option>
                        <option value="SouthEast">Bottom Right</option>
                        <option value="SouthWest">Bottom Left</option>
                    </select>
                </div>
            </div>
        </details>
    </div>

    <div class="grid grid-cols-12 gap-6">
        {{-- Left panel: Poster selector --}}
        <div class="col-span-3">
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Posters</h2>
            <div class="space-y-2 max-h-[calc(100vh-200px)] overflow-y-auto">
                @forelse($posters as $poster)
                    <label class="flex items-center gap-3 rounded-lg border p-3 cursor-pointer transition-colors hover:bg-gray-50 {{ in_array($poster->id, $selectedPosters) ? 'border-purple-500 bg-purple-50' : 'border-gray-200 bg-white' }}">
                        <input
                            type="checkbox"
                            value="{{ $poster->id }}"
                            wire:model.live="selectedPosters"
                            class="h-4 w-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500"
                        >
                        <div class="h-10 w-10 shrink-0 overflow-hidden rounded bg-gray-100">
                            @if($poster->thumbnail_url)
                                <img src="{{ $poster->thumbnail_url }}" class="h-full w-full object-cover">
                            @endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-900">{{ $poster->title }}</p>
                            <p class="text-xs text-gray-500">{{ $poster->status }}</p>
                        </div>
                    </label>
                @empty
                    <p class="text-sm text-gray-500 p-3">No upscaled posters available. <a href="/upscale" class="text-indigo-600 underline" wire:navigate>Upscale some first.</a></p>
                @endforelse
            </div>
        </div>

        {{-- Center: Preview --}}
        <div class="col-span-6">
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Preview</h2>
            <div class="flex items-center justify-center rounded-lg bg-white border border-gray-200 p-6 min-h-[400px]">
                @if($selectedTemplate)
                    @php $tpl = \App\Models\MockupTemplate::find($selectedTemplate); @endphp
                    @if($tpl && file_exists($tpl->background_path))
                        <div class="text-center w-full">
                            <img src="{{ route('template.image', $tpl) }}" class="max-h-[400px] mx-auto rounded shadow-md" alt="{{ $tpl->name }}">
                            <p class="mt-3 text-sm font-medium text-gray-700">{{ $tpl->name }}</p>
                            <p class="text-xs text-gray-500">{{ ucfirst(str_replace('-', ' ', $tpl->category)) }} &middot; {{ $tpl->aspect_ratio }}</p>

                            @if($this->isMultiSlot)
                                {{-- Multi-slot assignment UI --}}
                                <div class="mt-4 border-t border-gray-200 pt-4">
                                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Assign Posters to Slots</h3>
                                    <div class="space-y-3">
                                        @foreach($this->selectedTemplateSlots as $index => $slot)
                                            <div class="flex items-center gap-3 bg-gray-50 rounded-lg p-3">
                                                <span class="shrink-0 inline-flex items-center justify-center h-6 w-6 rounded-full bg-purple-100 text-purple-700 text-xs font-bold">{{ $index + 1 }}</span>
                                                <span class="shrink-0 text-sm font-medium text-gray-700 w-20 text-left">{{ $slot['label'] }}</span>
                                                <select
                                                    wire:change="assignPosterToSlot({{ $index }}, $event.target.value)"
                                                    class="flex-1 rounded-lg border-gray-300 text-sm focus:border-purple-500 focus:ring-purple-500"
                                                >
                                                    <option value="">-- Select poster --</option>
                                                    @foreach($posters as $poster)
                                                        <option value="{{ $poster->id }}" @selected(($slotAssignments[$index] ?? null) == $poster->id)>
                                                            {{ $poster->title }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                @if(($slotAssignments[$index] ?? null) && ($assignedPoster = $posters->firstWhere('id', $slotAssignments[$index])))
                                                    <div class="h-8 w-8 shrink-0 overflow-hidden rounded bg-gray-100">
                                                        @if($assignedPoster->thumbnail_url)
                                                            <img src="{{ $assignedPoster->thumbnail_url }}" class="h-full w-full object-cover">
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    <button
                                        wire:click="generateForTemplate({{ $tpl->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="generateForTemplate({{ $tpl->id }})"
                                        class="mt-4 inline-flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50"
                                    >
                                        <x-spinner wire:loading wire:target="generateForTemplate({{ $tpl->id }})" />
                                        <span wire:loading.remove wire:target="generateForTemplate({{ $tpl->id }})">Generate Multi-Image Mockup</span>
                                        <span wire:loading wire:target="generateForTemplate({{ $tpl->id }})">Generating...</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                @else
                    <p class="text-gray-400">Select a template to preview</p>
                @endif
            </div>
        </div>

        {{-- Right panel: Templates --}}
        <div class="col-span-3">
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Templates</h2>
            <div class="space-y-2 max-h-[calc(100vh-200px)] overflow-y-auto">
                @forelse($templates as $template)
                    @php $slotCount = count($template->getAllSlots()); @endphp
                    <div
                        wire:click="selectTemplate({{ $template->id }})"
                        class="cursor-pointer rounded-lg border p-3 transition-colors hover:bg-gray-50 {{ $selectedTemplate === $template->id ? 'border-purple-500 bg-purple-50' : 'border-gray-200 bg-white' }}"
                    >
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-medium text-gray-900">{{ $template->name }}</p>
                            @if($slotCount > 1)
                                <span class="inline-flex items-center rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700">{{ $slotCount }} slots</span>
                            @endif
                        </div>
                        <p class="text-xs text-gray-500">{{ ucfirst(str_replace('-', ' ', $template->category)) }} &middot; {{ $template->aspect_ratio }}</p>
                        @if(count($selectedPosters) > 0 && $slotCount <= 1)
                            <button
                                wire:click.stop="generateForTemplate({{ $template->id }})"
                                wire:loading.attr="disabled"
                                wire:target="generateForTemplate({{ $template->id }})"
                                class="mt-2 w-full inline-flex items-center justify-center gap-1.5 rounded bg-purple-100 px-3 py-1 text-xs font-medium text-purple-700 hover:bg-purple-200 disabled:opacity-50"
                            >
                                <x-spinner wire:loading wire:target="generateForTemplate({{ $template->id }})" class="h-3 w-3" />
                                <span wire:loading.remove wire:target="generateForTemplate({{ $template->id }})">Generate</span>
                                <span wire:loading wire:target="generateForTemplate({{ $template->id }})">Generating...</span>
                            </button>
                        @elseif($slotCount > 1 && $selectedTemplate === $template->id)
                            <p class="mt-2 text-xs text-purple-600 font-medium">Assign posters in the preview panel</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500 p-3">No templates yet. <a href="/templates/create" class="text-indigo-600 underline" wire:navigate>Create one.</a></p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Generated Mockups Gallery --}}
    @if($mockups->isNotEmpty())
        <div class="mt-8">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Generated Mockups</h2>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach($mockups as $mockup)
                    @if(file_exists($mockup->output_path))
                        <div class="group relative overflow-hidden rounded-lg border border-gray-200 bg-white">
                            <img
                                src="{{ route('mockup.image', $mockup) }}"
                                class="w-full object-cover"
                                alt="{{ $mockup->poster->title ?? '' }} - {{ $mockup->template->name ?? '' }}"
                                loading="lazy"
                            >
                            <div class="flex items-center justify-between p-2">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-medium text-gray-900">{{ $mockup->poster->title ?? 'Unknown' }}</p>
                                    <p class="truncate text-xs text-gray-500">{{ $mockup->template->name ?? 'Unknown' }}</p>
                                </div>
                                <div class="flex gap-1 shrink-0 ml-2">
                                    <a
                                        href="{{ route('mockup.download', $mockup) }}"
                                        download
                                        class="rounded bg-indigo-100 p-1.5 text-indigo-700 hover:bg-indigo-200"
                                        title="Download"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17v3a2 2 0 002 2h14a2 2 0 002-2v-3"/></svg>
                                    </a>
                                    <button
                                        wire:click="deleteMockup({{ $mockup->id }})"
                                        wire:confirm="Delete this mockup?"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteMockup({{ $mockup->id }})"
                                        class="rounded bg-red-100 p-1.5 text-red-700 hover:bg-red-200 disabled:opacity-50"
                                    >
                                        <x-spinner wire:loading wire:target="deleteMockup({{ $mockup->id }})" class="h-3.5 w-3.5" />
                                        <svg wire:loading.remove wire:target="deleteMockup({{ $mockup->id }})" class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</div>
