<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'PosterForge' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
    <div class="min-h-full">
        <nav class="border-b border-gray-200 bg-white">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-14 items-center justify-between">
                    <div class="flex items-center gap-8">
                        <a href="/" class="text-lg font-bold text-gray-900" wire:navigate>PosterForge</a>
                        <div class="flex gap-1">
                            <a href="/"
                               class="rounded-md px-3 py-2 text-sm font-medium {{ request()->is('/') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}"
                               wire:navigate>
                                Dashboard
                            </a>
                            <a href="/upscale"
                               class="rounded-md px-3 py-2 text-sm font-medium {{ request()->is('upscale') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}"
                               wire:navigate>
                                Upscale
                            </a>
                            <a href="/mockups"
                               class="rounded-md px-3 py-2 text-sm font-medium {{ request()->is('mockups') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}"
                               wire:navigate>
                                Mockups
                            </a>
                            <a href="/templates"
                               class="rounded-md px-3 py-2 text-sm font-medium {{ request()->is('templates*') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}"
                               wire:navigate>
                                Templates
                            </a>
                            <a href="/export"
                               class="rounded-md px-3 py-2 text-sm font-medium {{ request()->is('export') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}"
                               wire:navigate>
                                Export
                            </a>
                            <a href="/settings"
                               class="rounded-md px-3 py-2 text-sm font-medium {{ request()->is('settings') ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900' }}"
                               wire:navigate>
                                Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>

    {{-- Toast notifications --}}
    <div
        x-data="{
            toasts: [],
            add(type, message) {
                const id = Date.now();
                this.toasts.push({ id, type, message });
                setTimeout(() => this.remove(id), 4000);
            },
            remove(id) {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }
        }"
        @toast.window="add($event.detail.type, $event.detail.message)"
        class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"
    >
        <template x-for="toast in toasts" :key="toast.id">
            <div
                x-show="true"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-2"
                @click="remove(toast.id)"
                class="cursor-pointer rounded-lg px-4 py-3 text-sm font-medium shadow-lg max-w-sm"
                :class="{
                    'bg-green-600 text-white': toast.type === 'success',
                    'bg-red-600 text-white': toast.type === 'error',
                    'bg-blue-600 text-white': toast.type === 'info',
                    'bg-amber-500 text-white': toast.type === 'warning',
                }"
            >
                <span x-text="toast.message"></span>
            </div>
        </template>
    </div>
</body>
</html>
