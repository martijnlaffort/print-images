<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">
            {{ $templateId ? 'Edit Template' : 'Create Template' }}
        </h1>
        <a href="/templates" class="text-sm text-gray-500 hover:text-gray-700" wire:navigate>&larr; Back to Templates</a>
    </div>

    <div class="grid grid-cols-2 gap-6">
        {{-- Left: Corner editor --}}
        <div>
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Corner Placement</h2>
            <div
                x-data="{
                    dragging: null,
                    imageWidth: 0,
                    imageHeight: 0,
                    corners: @entangle('corners'),
                    startDrag(index, event) {
                        this.dragging = index;
                        event.preventDefault();
                    },
                    onMove(event) {
                        if (this.dragging === null) return;
                        const rect = this.$refs.canvas.getBoundingClientRect();
                        const scaleX = this.imageWidth / rect.width;
                        const scaleY = this.imageHeight / rect.height;
                        const x = Math.max(0, Math.min((event.clientX - rect.left) * scaleX, this.imageWidth));
                        const y = Math.max(0, Math.min((event.clientY - rect.top) * scaleY, this.imageHeight));
                        this.corners[this.dragging] = { x: Math.round(x), y: Math.round(y) };
                    },
                    endDrag() {
                        if (this.dragging !== null) {
                            $wire.updateCorner(this.dragging, this.corners[this.dragging].x, this.corners[this.dragging].y);
                        }
                        this.dragging = null;
                    },
                    initImage(img) {
                        this.imageWidth = img.naturalWidth;
                        this.imageHeight = img.naturalHeight;
                    }
                }"
                @mousemove.window="onMove($event)"
                @mouseup.window="endDrag()"
                class="relative select-none"
            >
                @if($template && file_exists($template->background_path))
                    <div x-ref="canvas" class="relative overflow-hidden rounded-lg border border-gray-300">
                        <img
                            src="data:image/jpeg;base64,{{ base64_encode(file_get_contents($template->background_path)) }}"
                            class="w-full"
                            @load="initImage($event.target)"
                            draggable="false"
                        >
                        {{-- Corner handles --}}
                        <template x-for="(corner, index) in corners" :key="index">
                            <div
                                @mousedown="startDrag(index, $event)"
                                class="absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 cursor-move rounded-full border-2 border-white bg-red-500 shadow-lg hover:scale-125 transition-transform"
                                :style="`left: ${(corner.x / imageWidth) * 100}%; top: ${(corner.y / imageHeight) * 100}%`"
                            >
                                <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-white bg-red-500 rounded px-1" x-text="['TL','TR','BR','BL'][index]"></span>
                            </div>
                        </template>
                        {{-- Lines connecting corners --}}
                        <svg class="absolute inset-0 w-full h-full pointer-events-none">
                            <template x-for="i in 4">
                                <line
                                    :x1="`${(corners[(i-1) % 4].x / imageWidth) * 100}%`"
                                    :y1="`${(corners[(i-1) % 4].y / imageHeight) * 100}%`"
                                    :x2="`${(corners[i % 4].x / imageWidth) * 100}%`"
                                    :y2="`${(corners[i % 4].y / imageHeight) * 100}%`"
                                    stroke="rgba(239, 68, 68, 0.7)"
                                    stroke-width="2"
                                    stroke-dasharray="4"
                                />
                            </template>
                        </svg>
                    </div>
                @elseif($backgroundImage)
                    <div x-ref="canvas" class="relative overflow-hidden rounded-lg border border-gray-300">
                        <img
                            src="{{ $backgroundImage->temporaryUrl() }}"
                            class="w-full"
                            @load="initImage($event.target)"
                            draggable="false"
                        >
                        <template x-for="(corner, index) in corners" :key="index">
                            <div
                                @mousedown="startDrag(index, $event)"
                                class="absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 cursor-move rounded-full border-2 border-white bg-red-500 shadow-lg hover:scale-125 transition-transform"
                                :style="`left: ${(corner.x / imageWidth) * 100}%; top: ${(corner.y / imageHeight) * 100}%`"
                            >
                                <span class="absolute -top-5 left-1/2 -translate-x-1/2 text-[10px] font-bold text-white bg-red-500 rounded px-1" x-text="['TL','TR','BR','BL'][index]"></span>
                            </div>
                        </template>
                        <svg class="absolute inset-0 w-full h-full pointer-events-none">
                            <template x-for="i in 4">
                                <line
                                    :x1="`${(corners[(i-1) % 4].x / imageWidth) * 100}%`"
                                    :y1="`${(corners[(i-1) % 4].y / imageHeight) * 100}%`"
                                    :x2="`${(corners[i % 4].x / imageWidth) * 100}%`"
                                    :y2="`${(corners[i % 4].y / imageHeight) * 100}%`"
                                    stroke="rgba(239, 68, 68, 0.7)"
                                    stroke-width="2"
                                    stroke-dasharray="4"
                                />
                            </template>
                        </svg>
                    </div>
                @else
                    <label class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 p-16 cursor-pointer hover:bg-gray-50">
                        <svg class="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M6.75 7.5h.008v.008H6.75V7.5z" />
                        </svg>
                        <p class="mt-3 text-sm text-gray-600">Upload a room scene image</p>
                        <input type="file" wire:model="backgroundImage" accept="image/*" class="hidden">
                    </label>
                @endif
            </div>

            {{-- Corner coordinates display --}}
            <div class="mt-4 grid grid-cols-4 gap-2">
                @foreach(['TL', 'TR', 'BR', 'BL'] as $i => $label)
                    <div class="rounded bg-gray-100 px-2 py-1 text-center text-xs text-gray-600">
                        {{ $label }}: {{ $corners[$i]['x'] }}, {{ $corners[$i]['y'] }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Right: Form fields --}}
        <div>
            <h2 class="mb-3 text-sm font-semibold text-gray-700 uppercase tracking-wide">Template Settings</h2>
            <div class="space-y-4 rounded-lg bg-white border border-gray-200 p-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" wire:model="name" placeholder="e.g. Scandinavian Living Room" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                    <select wire:model="category" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="living-room">Living Room</option>
                        <option value="bedroom">Bedroom</option>
                        <option value="office">Office</option>
                        <option value="hallway">Hallway</option>
                        <option value="minimalist">Minimalist</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Aspect Ratio</label>
                    <select wire:model="aspectRatio" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="portrait">Portrait</option>
                        <option value="landscape">Landscape</option>
                        <option value="square">Square</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Brightness Adjustment ({{ $brightnessAdjust }}%)</label>
                    <input type="range" wire:model.live="brightnessAdjust" min="50" max="150" class="w-full">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Background Image</label>
                    <input type="file" wire:model="backgroundImage" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:rounded file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium">
                    @error('backgroundImage') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Shadow Overlay (optional)</label>
                    <input type="file" wire:model="shadowImage" accept="image/png" class="w-full text-sm text-gray-500 file:mr-4 file:rounded file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Frame Overlay (optional)</label>
                    <input type="file" wire:model="frameImage" accept="image/png" class="w-full text-sm text-gray-500 file:mr-4 file:rounded file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium">
                </div>

                <button
                    wire:click="saveTemplate"
                    wire:loading.attr="disabled"
                    wire:target="saveTemplate"
                    class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="saveTemplate" />
                    <span wire:loading.remove wire:target="saveTemplate">{{ $templateId ? 'Update Template' : 'Save Template' }}</span>
                    <span wire:loading wire:target="saveTemplate">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>
