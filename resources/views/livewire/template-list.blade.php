<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Mockup Templates</h1>
        <a href="/templates/create" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" wire:navigate>
            New Template
        </a>
    </div>

    @if($templates->isEmpty())
        <div class="rounded-lg bg-white border border-gray-200 p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M6.75 7.5h.008v.008H6.75V7.5z" />
            </svg>
            <p class="mt-4 text-gray-600">No templates yet</p>
            <p class="mt-1 text-sm text-gray-500">Create your first room scene template to start generating mockups.</p>
            <a href="/templates/create" class="mt-4 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700" wire:navigate>
                Create Template
            </a>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($templates as $template)
                <div class="overflow-hidden rounded-lg bg-white border border-gray-200 shadow-sm">
                    <div class="aspect-video bg-gray-100 overflow-hidden">
                        @if(file_exists($template->background_path))
                            <img src="data:image/jpeg;base64,{{ base64_encode(file_get_contents($template->background_path)) }}" class="h-full w-full object-cover" alt="{{ $template->name }}">
                        @else
                            <div class="flex h-full items-center justify-center text-gray-400">
                                <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M6.75 7.5h.008v.008H6.75V7.5z" />
                                </svg>
                            </div>
                        @endif
                    </div>
                    <div class="p-4">
                        <h3 class="font-medium text-gray-900">{{ $template->name }}</h3>
                        <p class="text-sm text-gray-500">{{ ucfirst($template->category) }} &middot; {{ $template->aspect_ratio }}</p>
                        <div class="mt-3 flex gap-2">
                            <a href="/templates/{{ $template->id }}/edit" class="rounded bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200" wire:navigate>
                                Edit
                            </a>
                            <button
                                wire:click="deleteTemplate({{ $template->id }})"
                                wire:confirm="Delete this template?"
                                class="rounded bg-red-50 px-3 py-1 text-xs font-medium text-red-700 hover:bg-red-100"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
