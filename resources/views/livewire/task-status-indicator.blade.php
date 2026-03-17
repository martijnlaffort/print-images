<div
    wire:poll.3s="refreshTasks"
    x-data="{ open: false }"
    @click.outside="open = false"
    class="relative"
>
    @if($this->hasAnyTasks)
        {{-- Trigger button --}}
        <button
            @click="open = !open"
            class="relative flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 transition-colors"
        >
            {{-- Animated spinner when active --}}
            @if($this->activeCount > 0)
                <svg class="h-4 w-4 animate-spin text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-xs">{{ $this->activeCount }}</span>

                {{-- Animated dot --}}
                <span class="absolute -top-0.5 -right-0.5 flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-blue-500"></span>
                </span>
            @else
                <svg class="h-4 w-4 text-green-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            @endif
        </button>

        {{-- Dropdown panel --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute right-0 top-full mt-2 w-80 rounded-lg bg-white shadow-lg ring-1 ring-gray-200 z-50"
        >
            <div class="px-3 py-2 border-b border-gray-100">
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Background Tasks</h3>
            </div>

            <div class="max-h-72 overflow-y-auto divide-y divide-gray-50">
                @foreach($this->tasks as $task)
                    <div class="px-3 py-2.5 hover:bg-gray-50 transition-colors">
                        <div class="flex items-start gap-2">
                            {{-- Type icon --}}
                            @if($task->status === 'completed')
                                <svg class="h-4 w-4 mt-0.5 text-green-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            @elseif($task->status === 'failed')
                                <svg class="h-4 w-4 mt-0.5 text-red-500 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                </svg>
                            @elseif($task->type === 'pipeline')
                                <svg class="h-4 w-4 mt-0.5 text-indigo-500 shrink-0 animate-pulse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                            @elseif($task->type === 'export')
                                <svg class="h-4 w-4 mt-0.5 text-green-500 shrink-0 animate-pulse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                            @elseif($task->type === 'upscale')
                                <svg class="h-4 w-4 mt-0.5 text-blue-500 shrink-0 animate-pulse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                                </svg>
                            @else
                                <svg class="h-4 w-4 mt-0.5 text-purple-500 shrink-0 animate-pulse" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5A2.25 2.25 0 0022.5 18.75V5.25A2.25 2.25 0 0020.25 3H3.75A2.25 2.25 0 001.5 5.25v13.5A2.25 2.25 0 003.75 21z" />
                                </svg>
                            @endif

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ $task->name }}</p>

                                    @if(in_array($task->status, ['completed', 'failed']))
                                        <button
                                            wire:click="dismissTask({{ $task->id }})"
                                            class="text-gray-400 hover:text-gray-600 shrink-0"
                                        >
                                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>

                                {{-- Progress bar for active tasks --}}
                                @if(in_array($task->status, ['pending', 'running']))
                                    <div class="mt-1.5">
                                        <div class="flex items-center justify-between mb-0.5">
                                            <span class="text-xs text-gray-500">
                                                @if($task->stage)
                                                    {{ ucfirst($task->stage) }}
                                                @elseif($task->status === 'pending')
                                                    Waiting...
                                                @else
                                                    Processing...
                                                @endif
                                            </span>
                                            <span class="text-xs text-gray-500">{{ $task->progress }}%</span>
                                        </div>
                                        <div class="w-full bg-gray-100 rounded-full h-1.5">
                                            <div
                                                class="h-1.5 rounded-full transition-all duration-500 {{ $task->status === 'pending' ? 'bg-gray-300' : 'bg-blue-500' }}"
                                                style="width: {{ $task->progress }}%"
                                            ></div>
                                        </div>
                                        @if($task->total_items > 1)
                                            <p class="text-xs text-gray-400 mt-0.5">{{ $task->completed_items }}/{{ $task->total_items }} items</p>
                                        @endif
                                    </div>
                                @endif

                                {{-- Error message for failed tasks --}}
                                @if($task->status === 'failed' && $task->error_message)
                                    <p class="text-xs text-red-600 mt-1 line-clamp-2">{{ $task->error_message }}</p>
                                @endif

                                {{-- Completed state --}}
                                @if($task->status === 'completed')
                                    <p class="text-xs text-green-600 mt-0.5">Done</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
