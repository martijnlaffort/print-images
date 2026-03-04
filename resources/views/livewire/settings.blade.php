<div>
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
    </div>

    <div class="space-y-8 max-w-2xl">

        {{-- Export Defaults --}}
        <div class="rounded-lg bg-white border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Export Defaults</h2>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Default Output Folder</label>
                <div class="flex gap-2">
                    <input type="text" wire:model="defaultDir" class="flex-1 rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <button
                        wire:click="selectDir"
                        wire:loading.attr="disabled"
                        wire:target="selectDir"
                        class="shrink-0 inline-flex items-center gap-1.5 rounded-lg bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200 disabled:opacity-50"
                    >
                        <x-spinner wire:loading wire:target="selectDir" class="h-3 w-3" />
                        Browse
                    </button>
                </div>
            </div>
            <div class="mt-4">
                <button
                    wire:click="saveExportDefaults"
                    wire:loading.attr="disabled"
                    wire:target="saveExportDefaults"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="saveExportDefaults" />
                    <span wire:loading.remove wire:target="saveExportDefaults">Save</span>
                    <span wire:loading wire:target="saveExportDefaults">Saving...</span>
                </button>
            </div>
        </div>

        {{-- Naming Patterns --}}
        <div class="rounded-lg bg-white border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Naming Patterns</h2>
            <p class="text-xs text-gray-500 mb-4">Available tokens: <code class="bg-gray-100 rounded px-1">{title}</code> <code class="bg-gray-100 rounded px-1">{size}</code> <code class="bg-gray-100 rounded px-1">{template}</code> <code class="bg-gray-100 rounded px-1">{date}</code></p>

            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Upscaled</label>
                    <input type="text" wire:model="namingUpscaled" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Size Variant</label>
                    <input type="text" wire:model="namingSize" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mockup</label>
                    <input type="text" wire:model="namingMockup" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>

            <div class="mt-4">
                <button
                    wire:click="saveNamingPatterns"
                    wire:loading.attr="disabled"
                    wire:target="saveNamingPatterns"
                    class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="saveNamingPatterns" />
                    <span wire:loading.remove wire:target="saveNamingPatterns">Save</span>
                    <span wire:loading wire:target="saveNamingPatterns">Saving...</span>
                </button>
            </div>
        </div>

        {{-- Print Sizes --}}
        <div class="rounded-lg bg-white border border-gray-200 p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Print Sizes</h2>

            {{-- Built-in sizes --}}
            <table class="w-full text-sm mb-4">
                <thead>
                    <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wide">
                        <th class="pb-2">Name</th>
                        <th class="pb-2">Width (cm)</th>
                        <th class="pb-2">Height (cm)</th>
                        <th class="pb-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($builtInSizes as $name => $dims)
                        <tr>
                            <td class="py-2 font-medium text-gray-900">{{ $name }}</td>
                            <td class="py-2 text-gray-600">{{ $dims['width_cm'] }}</td>
                            <td class="py-2 text-gray-600">{{ $dims['height_cm'] }}</td>
                            <td class="py-2 text-xs text-gray-400">Built-in</td>
                        </tr>
                    @endforeach
                    @foreach($customSizes as $index => $size)
                        <tr>
                            <td class="py-2 font-medium text-gray-900">{{ $size['name'] }}</td>
                            <td class="py-2 text-gray-600">{{ $size['width_cm'] }}</td>
                            <td class="py-2 text-gray-600">{{ $size['height_cm'] }}</td>
                            <td class="py-2">
                                <button
                                    wire:click="removeCustomSize({{ $index }})"
                                    class="text-xs text-red-600 hover:text-red-800"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{-- Add custom size --}}
            <div class="flex items-end gap-3 rounded-lg bg-gray-50 p-3">
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Name</label>
                    <input type="text" wire:model="newSizeName" placeholder="e.g. 60x90" class="w-28 rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Width (cm)</label>
                    <input type="number" wire:model="newSizeWidth" step="0.1" class="w-24 rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Height (cm)</label>
                    <input type="number" wire:model="newSizeHeight" step="0.1" class="w-24 rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <button
                    wire:click="addCustomSize"
                    wire:loading.attr="disabled"
                    wire:target="addCustomSize"
                    class="inline-flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="addCustomSize" class="h-3 w-3" />
                    Add
                </button>
            </div>
            @error('newSizeName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('newSizeWidth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            @error('newSizeHeight') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</div>
