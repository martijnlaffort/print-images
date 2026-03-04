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

    {{-- Controls --}}
    <div class="mb-6 flex items-end gap-4 rounded-lg bg-white p-4 shadow-sm border border-gray-200">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Model</label>
            <select wire:model="model" class="rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                @foreach($models as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Scale Factor</label>
            <select wire:model="scale" class="rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="4">4x</option>
            </select>
        </div>
        <div class="min-w-48">
            <label class="block text-sm font-medium text-gray-700 mb-1">Denoise Strength: {{ $denoise }}%</label>
            <input type="range" wire:model.live="denoise" min="0" max="100" step="10"
                class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-indigo-600">
            <div class="flex justify-between text-xs text-gray-400 mt-0.5">
                <span>Sharp</span>
                <span>Smooth</span>
            </div>
        </div>
        <div class="ml-auto flex gap-2">
            @if(count($selected) > 0)
                <button
                    wire:click="startUpscale"
                    wire:loading.attr="disabled"
                    wire:target="startUpscale"
                    @disabled(!$binaryAvailable)
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <x-spinner wire:loading wire:target="startUpscale" />
                    <span wire:loading.remove wire:target="startUpscale">Upscale Selected ({{ count($selected) }})</span>
                    <span wire:loading wire:target="startUpscale">Queuing...</span>
                </button>
            @endif
        </div>
    </div>

    {{-- Poster list --}}
    <div class="mb-3">
        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" wire:model.live="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
            Select all unprocessed
        </label>
    </div>

    <div class="space-y-2">
        @forelse($posters as $poster)
            <div class="flex items-center gap-4 rounded-lg bg-white p-4 shadow-sm border border-gray-200">
                <input
                    type="checkbox"
                    value="{{ $poster->id }}"
                    wire:model.live="selected"
                    class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                    @disabled($poster->status !== 'imported')
                >

                <div class="h-12 w-12 shrink-0 overflow-hidden rounded bg-gray-100">
                    @if($poster->thumbnail_url)
                        <img src="{{ $poster->thumbnail_url }}" class="h-full w-full object-cover">
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-900">{{ $poster->title }}</p>
                    <p class="text-xs text-gray-500">{{ basename($poster->original_path) }}</p>
                </div>

                <span @class([
                    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                    'bg-gray-100 text-gray-700' => $poster->status === 'imported',
                    'bg-blue-100 text-blue-700' => $poster->status === 'upscaled',
                    'bg-purple-100 text-purple-700' => $poster->status === 'mockups_ready',
                    'bg-green-100 text-green-700' => $poster->status === 'exported',
                ])>
                    {{ str_replace('_', ' ', $poster->status) }}
                </span>

                @if($poster->status === 'imported' && $binaryAvailable)
                    <button
                        wire:click="upscaleSingle({{ $poster->id }})"
                        wire:loading.attr="disabled"
                        wire:target="upscaleSingle({{ $poster->id }})"
                        class="inline-flex items-center gap-1.5 rounded bg-indigo-100 px-3 py-1 text-xs font-medium text-indigo-700 hover:bg-indigo-200 disabled:opacity-50"
                    >
                        <x-spinner wire:loading wire:target="upscaleSingle({{ $poster->id }})" class="h-3 w-3" />
                        <span wire:loading.remove wire:target="upscaleSingle({{ $poster->id }})">Upscale</span>
                        <span wire:loading wire:target="upscaleSingle({{ $poster->id }})">Queuing...</span>
                    </button>
                @endif
            </div>
        @empty
            <div class="rounded-lg bg-white p-8 text-center shadow-sm border border-gray-200">
                <p class="text-gray-500">No posters imported yet. Go to the <a href="/" class="text-indigo-600 underline" wire:navigate>Dashboard</a> to import images.</p>
            </div>
        @endforelse
    </div>
</div>
