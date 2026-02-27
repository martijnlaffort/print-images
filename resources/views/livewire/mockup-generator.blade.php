<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Mockup Generator</h1>
        @if(count($selectedPosters) > 0 && $templates->isNotEmpty())
            <button
                wire:click="generateAll"
                class="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700"
            >
                Generate All ({{ count($selectedPosters) }} posters x {{ $templates->count() }} templates)
            </button>
        @endif
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
                        <div class="text-center">
                            <img src="data:image/jpeg;base64,{{ base64_encode(file_get_contents($tpl->background_path)) }}" class="max-h-[500px] rounded shadow-md" alt="{{ $tpl->name }}">
                            <p class="mt-3 text-sm font-medium text-gray-700">{{ $tpl->name }}</p>
                            <p class="text-xs text-gray-500">{{ $tpl->category }} &middot; {{ $tpl->aspect_ratio }}</p>
                        </div>
                    @endif
                @else
                    <p class="text-gray-400">Select a template to preview</p>
                @endif
            </div>
        </div>

        {{-- Right panel: Templates --}}
        <div class="col-span-3">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Templates</h2>
                @if(count($categories) > 0)
                    <select wire:model.live="categoryFilter" class="rounded border-gray-300 text-xs focus:border-purple-500 focus:ring-purple-500">
                        <option value="">All</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div class="space-y-2 max-h-[calc(100vh-200px)] overflow-y-auto">
                @forelse($templates as $template)
                    <div
                        wire:click="selectTemplate({{ $template->id }})"
                        class="cursor-pointer rounded-lg border p-3 transition-colors hover:bg-gray-50 {{ $selectedTemplate === $template->id ? 'border-purple-500 bg-purple-50' : 'border-gray-200 bg-white' }}"
                    >
                        <p class="text-sm font-medium text-gray-900">{{ $template->name }}</p>
                        <p class="text-xs text-gray-500">{{ ucfirst($template->category) }} &middot; {{ $template->aspect_ratio }}</p>
                        @if(count($selectedPosters) > 0)
                            <button
                                wire:click.stop="generateForTemplate({{ $template->id }})"
                                class="mt-2 w-full rounded bg-purple-100 px-3 py-1 text-xs font-medium text-purple-700 hover:bg-purple-200"
                            >
                                Generate
                            </button>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-500 p-3">No templates yet. <a href="/templates/create" class="text-indigo-600 underline" wire:navigate>Create one.</a></p>
                @endforelse
            </div>
        </div>
    </div>
</div>
