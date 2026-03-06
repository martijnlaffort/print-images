<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">
            {{ $templateId ? 'Edit Template' : 'Create Template' }}
        </h1>
        <a href="/templates" class="text-sm text-gray-500 hover:text-gray-700" wire:navigate>&larr; Back to Templates</a>
    </div>

    <div class="grid grid-cols-3 gap-6">
        {{-- Left: Corner editor with live preview (2 columns wide) --}}
        <div class="col-span-2">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Corner Placement</h2>
                {{-- Presets --}}
                <div class="flex items-center gap-1">
                    <span class="text-xs text-gray-500 mr-1">Presets:</span>
                    <button wire:click="applyPreset('centered')" class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">Centered</button>
                    <button wire:click="applyPreset('centered-large')" class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">Large</button>
                    <button wire:click="applyPreset('angled-left')" class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">Angled L</button>
                    <button wire:click="applyPreset('angled-right')" class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">Angled R</button>
                    <button wire:click="applyPreset('above-sofa')" class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200">Above Sofa</button>
                </div>
            </div>

            {{-- Slot tabs --}}
            <div class="flex items-center gap-2 mb-3">
                @foreach($posterSlots as $i => $slot)
                    <button
                        wire:click="switchSlot({{ $i }})"
                        @class([
                            'rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                            'bg-indigo-600 text-white' => $activeSlot === $i,
                            'bg-gray-100 text-gray-600 hover:bg-gray-200' => $activeSlot !== $i,
                        ])
                    >
                        {{ $slot['label'] }}
                    </button>
                @endforeach
                <button
                    wire:click="addSlot"
                    class="rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs text-gray-400 hover:border-gray-400 hover:text-gray-600"
                >
                    + Add Poster Slot
                </button>
                @if(count($posterSlots) > 1)
                    <button
                        wire:click="removeSlot({{ $activeSlot }})"
                        class="rounded-lg px-2 py-1.5 text-xs text-red-400 hover:text-red-600"
                    >
                        Remove
                    </button>
                @endif
            </div>

            <div
                x-data="{
                    dragging: null,
                    dragMode: null, // 'corner', 'move', 'draw', 'edge', 'pan'
                    dragEdge: null,
                    drawStart: null,
                    moveStart: null,
                    moveOriginalCorners: null,
                    selectedCorner: null,
                    imageWidth: 0,
                    imageHeight: 0,
                    corners: @entangle('corners'),
                    allSlots: @entangle('posterSlots'),
                    activeSlot: @entangle('activeSlot'),
                    sampleImage: null,
                    sampleLoaded: false,
                    showGrid: false,
                    showPreview: true,
                    toolMode: 'select', // 'select' or 'draw'
                    lastSampleSrc: '',
                    // Undo history
                    history: [],
                    historyIndex: -1,
                    maxHistory: 50,
                    skipHistoryPush: false,
                    // Zoom & pan
                    zoom: 1,
                    panX: 0,
                    panY: 0,
                    panStart: null,
                    panOriginal: null,
                    spaceDown: false,
                    init() {
                        this.pushHistory();
                        this.loadSampleImage();
                        this.$watch('corners', () => this.renderPreview());
                        this.$watch('activeSlot', () => this.renderPreview());
                        this.$watch('showPreview', () => this.renderPreview());
                        Livewire.hook('morph.updated', ({el}) => {
                            this.$nextTick(() => {
                                const newSrc = this.$refs.sampleSrc?.value || '';
                                if (newSrc !== this.lastSampleSrc) {
                                    this.lastSampleSrc = newSrc;
                                    this.loadSampleImage();
                                }
                            });
                        });
                    },
                    onWheel(event) {
                        event.preventDefault();
                        const wrapper = this.$refs.zoomWrapper;
                        if (!wrapper) return;
                        const rect = wrapper.getBoundingClientRect();
                        // Mouse position relative to wrapper
                        const mx = event.clientX - rect.left;
                        const my = event.clientY - rect.top;
                        const oldZoom = this.zoom;
                        const delta = event.deltaY > 0 ? -0.15 : 0.15;
                        this.zoom = Math.max(1, Math.min(8, this.zoom + delta * this.zoom));
                        // Adjust pan so zoom centers on mouse
                        const scale = this.zoom / oldZoom;
                        this.panX = mx - scale * (mx - this.panX);
                        this.panY = my - scale * (my - this.panY);
                        this.clampPan();
                    },
                    clampPan() {
                        const wrapper = this.$refs.zoomWrapper;
                        if (!wrapper) return;
                        const rect = wrapper.getBoundingClientRect();
                        const w = rect.width;
                        const h = rect.height;
                        // Don't let the image pan outside the viewport
                        const maxPanX = 0;
                        const minPanX = w - w * this.zoom;
                        const maxPanY = 0;
                        const minPanY = h - h * this.zoom;
                        this.panX = Math.max(minPanX, Math.min(maxPanX, this.panX));
                        this.panY = Math.max(minPanY, Math.min(maxPanY, this.panY));
                    },
                    resetZoom() {
                        this.zoom = 1;
                        this.panX = 0;
                        this.panY = 0;
                    },
                    zoomToFit() {
                        // Zoom to fit the quad in the viewport
                        const c = this.corners;
                        const minX = Math.min(c[0].x, c[1].x, c[2].x, c[3].x);
                        const maxX = Math.max(c[0].x, c[1].x, c[2].x, c[3].x);
                        const minY = Math.min(c[0].y, c[1].y, c[2].y, c[3].y);
                        const maxY = Math.max(c[0].y, c[1].y, c[2].y, c[3].y);
                        const padding = 80;
                        const wrapper = this.$refs.zoomWrapper;
                        if (!wrapper) return;
                        const rect = wrapper.getBoundingClientRect();
                        const quadW = (maxX - minX) / this.imageWidth * rect.width;
                        const quadH = (maxY - minY) / this.imageHeight * rect.height;
                        if (quadW <= 0 || quadH <= 0) return;
                        this.zoom = Math.max(1, Math.min(8, Math.min(
                            (rect.width - padding * 2) / quadW,
                            (rect.height - padding * 2) / quadH
                        )));
                        // Center on the quad
                        const cx = ((minX + maxX) / 2) / this.imageWidth * rect.width;
                        const cy = ((minY + maxY) / 2) / this.imageHeight * rect.height;
                        this.panX = rect.width / 2 - cx * this.zoom;
                        this.panY = rect.height / 2 - cy * this.zoom;
                        this.clampPan();
                    },
                    startPan(event) {
                        this.dragMode = 'pan';
                        this.panStart = { x: event.clientX, y: event.clientY };
                        this.panOriginal = { x: this.panX, y: this.panY };
                        event.preventDefault();
                    },
                    pushHistory() {
                        if (this.skipHistoryPush) return;
                        const state = JSON.stringify(this.corners);
                        // Don't push if same as current
                        if (this.historyIndex >= 0 && this.history[this.historyIndex] === state) return;
                        // Truncate any future states
                        this.history = this.history.slice(0, this.historyIndex + 1);
                        this.history.push(state);
                        if (this.history.length > this.maxHistory) this.history.shift();
                        this.historyIndex = this.history.length - 1;
                    },
                    undo() {
                        if (this.historyIndex <= 0) return;
                        this.historyIndex--;
                        this.skipHistoryPush = true;
                        this.corners = JSON.parse(this.history[this.historyIndex]);
                        this.corners.forEach((c, i) => $wire.updateCorner(i, c.x, c.y));
                        this.$nextTick(() => { this.skipHistoryPush = false; });
                    },
                    redo() {
                        if (this.historyIndex >= this.history.length - 1) return;
                        this.historyIndex++;
                        this.skipHistoryPush = true;
                        this.corners = JSON.parse(this.history[this.historyIndex]);
                        this.corners.forEach((c, i) => $wire.updateCorner(i, c.x, c.y));
                        this.$nextTick(() => { this.skipHistoryPush = false; });
                    },
                    get canUndo() { return this.historyIndex > 0; },
                    get canRedo() { return this.historyIndex < this.history.length - 1; },
                    loadSampleImage() {
                        const src = this.$refs.sampleSrc?.value;
                        this.lastSampleSrc = src || '';
                        if (!src) {
                            this.sampleLoaded = false;
                            this.sampleImage = null;
                            this.renderPreview();
                            return;
                        }
                        const img = new Image();
                        img.onload = () => {
                            this.sampleImage = img;
                            this.sampleLoaded = true;
                            this.renderPreview();
                        };
                        img.src = src;
                    },
                    toImageCoords(event) {
                        const wrapper = this.$refs.zoomWrapper;
                        if (!wrapper) return { x: 0, y: 0 };
                        const rect = wrapper.getBoundingClientRect();
                        // Account for zoom and pan: screen -> wrapper -> image
                        const wx = (event.clientX - rect.left - this.panX) / this.zoom;
                        const wy = (event.clientY - rect.top - this.panY) / this.zoom;
                        const scaleX = this.imageWidth / (rect.width);
                        const scaleY = this.imageHeight / (rect.height);
                        return {
                            x: Math.max(0, Math.min(Math.round(wx * scaleX), this.imageWidth)),
                            y: Math.max(0, Math.min(Math.round(wy * scaleY), this.imageHeight)),
                        };
                    },
                    pointInQuad(px, py) {
                        // Ray casting to check if point is inside the quad
                        const c = this.corners;
                        const pts = [c[0], c[1], c[2], c[3]];
                        let inside = false;
                        for (let i = 0, j = pts.length - 1; i < pts.length; j = i++) {
                            const xi = pts[i].x, yi = pts[i].y;
                            const xj = pts[j].x, yj = pts[j].y;
                            if (((yi > py) !== (yj > py)) && (px < (xj - xi) * (py - yi) / (yj - yi) + xi)) {
                                inside = !inside;
                            }
                        }
                        return inside;
                    },
                    nearestEdge(px, py) {
                        // Check if close to an edge, return edge index (0=top, 1=right, 2=bottom, 3=left) or null
                        const c = this.corners;
                        const edges = [[0,1],[1,2],[2,3],[3,0]];
                        const threshold = 15 * (this.imageWidth / (this.$refs.canvas?.getBoundingClientRect().width || 1));
                        for (let e = 0; e < edges.length; e++) {
                            const [a, b] = edges[e];
                            const dist = this.pointToSegmentDist(px, py, c[a].x, c[a].y, c[b].x, c[b].y);
                            if (dist < threshold) return e;
                        }
                        return null;
                    },
                    pointToSegmentDist(px, py, x1, y1, x2, y2) {
                        const dx = x2 - x1, dy = y2 - y1;
                        const len2 = dx * dx + dy * dy;
                        if (len2 === 0) return Math.sqrt((px - x1) ** 2 + (py - y1) ** 2);
                        let t = Math.max(0, Math.min(1, ((px - x1) * dx + (py - y1) * dy) / len2));
                        const projX = x1 + t * dx, projY = y1 + t * dy;
                        return Math.sqrt((px - projX) ** 2 + (py - projY) ** 2);
                    },
                    onCanvasDown(event) {
                        if (!this.imageWidth) return;

                        // Middle mouse or Space+click = pan
                        if (event.button === 1 || this.spaceDown) {
                            this.startPan(event);
                            return;
                        }

                        const pos = this.toImageCoords(event);

                        if (this.toolMode === 'draw') {
                            this.dragMode = 'draw';
                            this.drawStart = pos;
                            event.preventDefault();
                            return;
                        }

                        // Check if near an edge
                        const edge = this.nearestEdge(pos.x, pos.y);
                        if (edge !== null && !this.pointInQuad(pos.x, pos.y)) {
                            this.dragMode = 'edge';
                            this.dragEdge = edge;
                            this.moveStart = pos;
                            this.moveOriginalCorners = this.corners.map(c => ({...c}));
                            event.preventDefault();
                            return;
                        }

                        // Check if inside quad for move
                        if (this.pointInQuad(pos.x, pos.y)) {
                            this.dragMode = 'move';
                            this.moveStart = pos;
                            this.moveOriginalCorners = this.corners.map(c => ({...c}));
                            event.preventDefault();
                            return;
                        }
                    },
                    startDrag(index, event) {
                        this.dragging = index;
                        this.dragMode = 'corner';
                        this.selectedCorner = index;
                        event.preventDefault();
                        event.stopPropagation();
                    },
                    onMove(event) {
                        if (!this.imageWidth) return;

                        if (this.dragMode === 'pan' && this.panStart) {
                            this.panX = this.panOriginal.x + (event.clientX - this.panStart.x);
                            this.panY = this.panOriginal.y + (event.clientY - this.panStart.y);
                            this.clampPan();
                            return;
                        }

                        const pos = this.toImageCoords(event);

                        if (this.dragMode === 'corner' && this.dragging !== null) {
                            this.corners[this.dragging] = pos;
                            return;
                        }

                        if (this.dragMode === 'move' && this.moveStart) {
                            const dx = pos.x - this.moveStart.x;
                            const dy = pos.y - this.moveStart.y;
                            for (let i = 0; i < 4; i++) {
                                this.corners[i] = {
                                    x: Math.max(0, Math.min(this.imageWidth, this.moveOriginalCorners[i].x + dx)),
                                    y: Math.max(0, Math.min(this.imageHeight, this.moveOriginalCorners[i].y + dy)),
                                };
                            }
                            return;
                        }

                        if (this.dragMode === 'edge' && this.moveStart) {
                            const dx = pos.x - this.moveStart.x;
                            const dy = pos.y - this.moveStart.y;
                            const edgeCorners = [[0,1],[1,2],[2,3],[3,0]][this.dragEdge];
                            // Move only the two corners of this edge
                            const updated = this.moveOriginalCorners.map(c => ({...c}));
                            for (const ci of edgeCorners) {
                                updated[ci] = {
                                    x: Math.max(0, Math.min(this.imageWidth, this.moveOriginalCorners[ci].x + dx)),
                                    y: Math.max(0, Math.min(this.imageHeight, this.moveOriginalCorners[ci].y + dy)),
                                };
                            }
                            this.corners = updated;
                            return;
                        }

                        if (this.dragMode === 'draw' && this.drawStart) {
                            this.corners = [
                                { x: Math.min(this.drawStart.x, pos.x), y: Math.min(this.drawStart.y, pos.y) },
                                { x: Math.max(this.drawStart.x, pos.x), y: Math.min(this.drawStart.y, pos.y) },
                                { x: Math.max(this.drawStart.x, pos.x), y: Math.max(this.drawStart.y, pos.y) },
                                { x: Math.min(this.drawStart.x, pos.x), y: Math.max(this.drawStart.y, pos.y) },
                            ];
                            return;
                        }
                    },
                    endDrag() {
                        if (this.dragMode && this.dragMode !== 'pan') {
                            this.corners.forEach((c, i) => {
                                $wire.updateCorner(i, c.x, c.y);
                            });
                            this.pushHistory();
                        }
                        this.dragging = null;
                        this.dragMode = null;
                        this.dragEdge = null;
                        this.drawStart = null;
                        this.moveStart = null;
                        this.moveOriginalCorners = null;
                        this.panStart = null;
                        this.panOriginal = null;
                    },
                    nudge(dx, dy) {
                        if (this.selectedCorner !== null) {
                            const c = this.corners[this.selectedCorner];
                            this.corners[this.selectedCorner] = {
                                x: Math.max(0, Math.min(this.imageWidth, c.x + dx)),
                                y: Math.max(0, Math.min(this.imageHeight, c.y + dy)),
                            };
                            $wire.updateCorner(this.selectedCorner, this.corners[this.selectedCorner].x, this.corners[this.selectedCorner].y);
                            this.pushHistory();
                        }
                    },
                    nudgeAll(dx, dy) {
                        for (let i = 0; i < 4; i++) {
                            this.corners[i] = {
                                x: Math.max(0, Math.min(this.imageWidth, this.corners[i].x + dx)),
                                y: Math.max(0, Math.min(this.imageHeight, this.corners[i].y + dy)),
                            };
                        }
                        this.corners.forEach((c, i) => $wire.updateCorner(i, c.x, c.y));
                        this.pushHistory();
                    },
                    handleKeydown(event) {
                        // Ctrl+Z = undo, Ctrl+Shift+Z / Ctrl+Y = redo
                        if ((event.ctrlKey || event.metaKey) && event.key === 'z' && !event.shiftKey) {
                            this.undo();
                            event.preventDefault();
                            return;
                        }
                        if ((event.ctrlKey || event.metaKey) && (event.key === 'Z' || event.key === 'y')) {
                            this.redo();
                            event.preventDefault();
                            return;
                        }
                        if (event.key === ' ') {
                            this.spaceDown = true;
                            event.preventDefault();
                            return;
                        }
                        // 0 or Home = reset zoom
                        if (event.key === '0' || event.key === 'Home') {
                            this.resetZoom();
                            event.preventDefault();
                            return;
                        }
                        // F = zoom to fit quad
                        if (event.key === 'f' || event.key === 'F') {
                            this.zoomToFit();
                            event.preventDefault();
                            return;
                        }
                        // +/- for keyboard zoom
                        if (event.key === '+' || event.key === '=') {
                            this.zoom = Math.min(8, this.zoom * 1.25);
                            this.clampPan();
                            event.preventDefault();
                            return;
                        }
                        if (event.key === '-' || event.key === '_') {
                            this.zoom = Math.max(1, this.zoom / 1.25);
                            this.clampPan();
                            event.preventDefault();
                            return;
                        }
                        const step = event.shiftKey ? 10 : 1;
                        const map = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
                        const delta = map[event.key];
                        if (!delta) return;
                        event.preventDefault();
                        if (this.selectedCorner !== null) {
                            this.nudge(delta[0], delta[1]);
                        } else {
                            this.nudgeAll(delta[0], delta[1]);
                        }
                    },
                    handleKeyup(event) {
                        if (event.key === ' ') {
                            this.spaceDown = false;
                        }
                    },
                    selectCorner(index) {
                        this.selectedCorner = this.selectedCorner === index ? null : index;
                    },
                    initImage(img) {
                        this.imageWidth = img.naturalWidth;
                        this.imageHeight = img.naturalHeight;
                        this.renderPreview();
                    },
                    renderPreview() {
                        const canvas = this.$refs.previewCanvas;
                        if (!canvas || !this.imageWidth) return;
                        const ctx = canvas.getContext('2d');
                        canvas.width = this.imageWidth;
                        canvas.height = this.imageHeight;
                        ctx.clearRect(0, 0, canvas.width, canvas.height);

                        // Render all slots
                        for (let si = 0; si < this.allSlots.length; si++) {
                            const slotCorners = si === this.activeSlot ? this.corners : this.allSlots[si].corners;
                            const isActive = si === this.activeSlot;

                            // Draw outline for all modes
                            ctx.beginPath();
                            ctx.moveTo(slotCorners[0].x, slotCorners[0].y);
                            ctx.lineTo(slotCorners[1].x, slotCorners[1].y);
                            ctx.lineTo(slotCorners[2].x, slotCorners[2].y);
                            ctx.lineTo(slotCorners[3].x, slotCorners[3].y);
                            ctx.closePath();

                            if (!this.showPreview || !this.sampleLoaded || !this.sampleImage) {
                                // Outline-only mode: semi-transparent fill + visible stroke
                                ctx.fillStyle = isActive ? 'rgba(99, 102, 241, 0.12)' : 'rgba(156, 163, 175, 0.08)';
                                ctx.fill();
                                ctx.strokeStyle = isActive ? 'rgba(99, 102, 241, 0.6)' : 'rgba(156, 163, 175, 0.4)';
                                ctx.lineWidth = isActive ? 3 : 1.5;
                                ctx.setLineDash(isActive ? [8, 4] : [5, 5]);
                                ctx.stroke();
                                ctx.setLineDash([]);
                                // Draw diagonal cross to mark the area
                                if (isActive) {
                                    ctx.strokeStyle = 'rgba(99, 102, 241, 0.25)';
                                    ctx.lineWidth = 1;
                                    ctx.beginPath();
                                    ctx.moveTo(slotCorners[0].x, slotCorners[0].y);
                                    ctx.lineTo(slotCorners[2].x, slotCorners[2].y);
                                    ctx.moveTo(slotCorners[1].x, slotCorners[1].y);
                                    ctx.lineTo(slotCorners[3].x, slotCorners[3].y);
                                    ctx.stroke();
                                }
                                continue;
                            }

                            const sw = this.sampleImage.width;
                            const sh = this.sampleImage.height;
                            const steps = 30;
                            const opacity = isActive ? 1.0 : 0.4;
                            ctx.globalAlpha = opacity;

                            for (let gy = 0; gy < steps; gy++) {
                                for (let gx = 0; gx < steps; gx++) {
                                    const u0 = gx / steps, u1 = (gx + 1) / steps;
                                    const v0 = gy / steps, v1 = (gy + 1) / steps;
                                    const tl = this.bilinear(u0, v0, slotCorners);
                                    const tr = this.bilinear(u1, v0, slotCorners);
                                    const br = this.bilinear(u1, v1, slotCorners);
                                    const bl = this.bilinear(u0, v1, slotCorners);
                                    this.drawTexturedQuad(ctx, this.sampleImage, u0 * sw, v0 * sh, (u1 - u0) * sw, (v1 - v0) * sh, tl, tr, br, bl);
                                }
                            }
                            ctx.globalAlpha = 1.0;
                        }

                        // Grid for active slot only
                        if (this.showGrid) {
                            ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)';
                            ctx.lineWidth = 1;
                            for (let i = 1; i < 4; i++) {
                                const t = i / 4;
                                const left = this.bilinear(0, t, this.corners);
                                const right = this.bilinear(1, t, this.corners);
                                ctx.beginPath(); ctx.moveTo(left.x, left.y); ctx.lineTo(right.x, right.y); ctx.stroke();
                                const top = this.bilinear(t, 0, this.corners);
                                const bottom = this.bilinear(t, 1, this.corners);
                                ctx.beginPath(); ctx.moveTo(top.x, top.y); ctx.lineTo(bottom.x, bottom.y); ctx.stroke();
                            }
                        }
                    },
                    bilinear(u, v, c) {
                        c = c || this.corners;
                        return {
                            x: (1-u)*(1-v)*c[0].x + u*(1-v)*c[1].x + u*v*c[2].x + (1-u)*v*c[3].x,
                            y: (1-u)*(1-v)*c[0].y + u*(1-v)*c[1].y + u*v*c[2].y + (1-u)*v*c[3].y,
                        };
                    },
                    drawTexturedQuad(ctx, img, sx, sy, sw, sh, tl, tr, br, bl) {
                        ctx.save();
                        ctx.beginPath();
                        ctx.moveTo(tl.x, tl.y); ctx.lineTo(tr.x, tr.y); ctx.lineTo(br.x, br.y); ctx.lineTo(bl.x, bl.y);
                        ctx.closePath(); ctx.clip();
                        const dx1 = tr.x - tl.x, dy1 = tr.y - tl.y;
                        const dx2 = bl.x - tl.x, dy2 = bl.y - tl.y;
                        ctx.setTransform(dx1/sw, dy1/sw, dx2/sh, dy2/sh, tl.x-(dx1/sw)*sx-(dx2/sh)*sy, tl.y-(dy1/sw)*sx-(dy2/sh)*sy);
                        ctx.drawImage(img, sx, sy, sw, sh, sx, sy, sw, sh);
                        ctx.restore();
                    },
                    applyPresetCorners(relativeCorners) {
                        if (!this.imageWidth || !this.imageHeight) return;
                        this.corners = relativeCorners.map(c => ({
                            x: Math.round(c.x * this.imageWidth),
                            y: Math.round(c.y * this.imageHeight),
                        }));
                        this.corners.forEach((c, i) => { $wire.updateCorner(i, c.x, c.y); });
                        this.pushHistory();
                    }
                }"
                @mousemove.window="onMove($event)"
                @mouseup.window="endDrag()"
                @keydown.window="handleKeydown($event)"
                @keyup.window="handleKeyup($event)"
                @apply-preset.window="applyPresetCorners($event.detail.corners)"
                class="relative select-none"
                tabindex="0"
            >
                {{-- Sample poster data --}}
                <input type="hidden" x-ref="sampleSrc" value="{{ $samplePosterImage ?? '' }}">

                {{-- Tool mode toggle --}}
                <div class="flex items-center gap-2 mb-2">
                    <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                        <button
                            @click="toolMode = 'select'; selectedCorner = null"
                            :class="toolMode === 'select' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                            class="px-3 py-1.5 text-xs font-medium transition-colors"
                        >
                            <span class="flex items-center gap-1">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" /></svg>
                                Select
                            </span>
                        </button>
                        <button
                            @click="toolMode = 'draw'; selectedCorner = null"
                            :class="toolMode === 'draw' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'"
                            class="px-3 py-1.5 text-xs font-medium transition-colors border-l border-gray-200"
                        >
                            <span class="flex items-center gap-1">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v14a1 1 0 01-1 1H5a1 1 0 01-1-1V5z" /></svg>
                                Draw Rectangle
                            </span>
                        </button>
                    </div>
                    <span class="text-[10px] text-gray-400" x-show="toolMode === 'select'">
                        Click corner to select, drag to move. Drag inside quad to reposition. Arrow keys to nudge (Shift = 10px).
                    </span>
                    <span class="text-[10px] text-gray-400" x-show="toolMode === 'draw'">
                        Click and drag on the image to draw the poster area.
                    </span>
                </div>

                @if($template && file_exists($template->background_path))
                    <div x-ref="zoomWrapper" class="relative overflow-hidden rounded-lg border border-gray-300"
                         :class="[
                            toolMode === 'draw' ? 'cursor-crosshair' : '',
                            dragMode === 'pan' || spaceDown ? 'cursor-grab' : '',
                            dragMode === 'move' ? 'cursor-grabbing' : '',
                         ]"
                         @mousedown="onCanvasDown($event)"
                         @wheel.prevent="onWheel($event)"
                         @contextmenu.prevent>
                        <div x-ref="canvas" class="relative origin-top-left"
                             :style="`transform: translate(${panX}px, ${panY}px) scale(${zoom}); transform-origin: 0 0;`">
                            <img
                                src="{{ route('template.image', $template) }}"
                                class="w-full"
                                @load="initImage($event.target)"
                                draggable="false"
                            >
                            <canvas x-ref="previewCanvas" class="absolute inset-0 w-full h-full pointer-events-none"></canvas>
                            {{-- Corner handles for active slot --}}
                            <template x-for="(corner, index) in corners" :key="index">
                                <div
                                    @mousedown.stop="startDrag(index, $event)"
                                    @click.stop="selectCorner(index)"
                                    class="absolute -translate-x-1/2 -translate-y-1/2 cursor-move rounded-full border-2 shadow-lg hover:scale-110 transition-all z-10"
                                    :class="selectedCorner === index
                                        ? 'border-yellow-300 bg-yellow-400 ring-2 ring-yellow-300 ring-offset-1'
                                        : 'border-white bg-indigo-500'"
                                    :style="`left: ${(corner.x / imageWidth) * 100}%; top: ${(corner.y / imageHeight) * 100}%; width: ${Math.max(16, 24 / zoom)}px; height: ${Math.max(16, 24 / zoom)}px;`"
                                >
                                    <span class="absolute -top-5 left-1/2 -translate-x-1/2 font-bold text-white rounded px-1 py-0.5 whitespace-nowrap pointer-events-none"
                                          :class="selectedCorner === index ? 'bg-yellow-500' : 'bg-indigo-600'"
                                          :style="`font-size: ${Math.max(8, 10 / zoom)}px;`"
                                          x-text="['TL','TR','BR','BL'][index]"></span>
                                </div>
                            </template>
                            {{-- Lines for active slot --}}
                            <svg class="absolute inset-0 w-full h-full pointer-events-none z-[5]">
                                <template x-for="i in 4">
                                    <line
                                        :x1="`${(corners[(i-1) % 4].x / imageWidth) * 100}%`"
                                        :y1="`${(corners[(i-1) % 4].y / imageHeight) * 100}%`"
                                        :x2="`${(corners[i % 4].x / imageWidth) * 100}%`"
                                        :y2="`${(corners[i % 4].y / imageHeight) * 100}%`"
                                        stroke="rgba(99, 102, 241, 0.8)"
                                        :stroke-width="Math.max(1, 2 / zoom)"
                                        stroke-dasharray="6,3"
                                    />
                                </template>
                                {{-- Lines for inactive slots --}}
                                <template x-for="(slot, si) in allSlots">
                                    <template x-if="si !== activeSlot">
                                        <g>
                                            <template x-for="i in 4">
                                                <line
                                                    :x1="`${(slot.corners[(i-1) % 4].x / imageWidth) * 100}%`"
                                                    :y1="`${(slot.corners[(i-1) % 4].y / imageHeight) * 100}%`"
                                                    :x2="`${(slot.corners[i % 4].x / imageWidth) * 100}%`"
                                                    :y2="`${(slot.corners[i % 4].y / imageHeight) * 100}%`"
                                                    stroke="rgba(156, 163, 175, 0.5)"
                                                    :stroke-width="Math.max(0.5, 1 / zoom)"
                                                    stroke-dasharray="4,4"
                                                />
                                            </template>
                                        </g>
                                    </template>
                                </template>
                            </svg>
                        </div>
                    </div>
                @elseif($backgroundImage)
                    <div x-ref="zoomWrapper" class="relative overflow-hidden rounded-lg border border-gray-300"
                         :class="[
                            toolMode === 'draw' ? 'cursor-crosshair' : '',
                            dragMode === 'pan' || spaceDown ? 'cursor-grab' : '',
                         ]"
                         @mousedown="onCanvasDown($event)"
                         @wheel.prevent="onWheel($event)"
                         @contextmenu.prevent>
                        <div x-ref="canvas" class="relative origin-top-left"
                             :style="`transform: translate(${panX}px, ${panY}px) scale(${zoom}); transform-origin: 0 0;`">
                            <img
                                src="{{ $backgroundImage->temporaryUrl() }}"
                                class="w-full"
                                @load="initImage($event.target)"
                                draggable="false"
                            >
                            <canvas x-ref="previewCanvas" class="absolute inset-0 w-full h-full pointer-events-none"></canvas>
                            <template x-for="(corner, index) in corners" :key="index">
                                <div
                                    @mousedown.stop="startDrag(index, $event)"
                                    @click.stop="selectCorner(index)"
                                    class="absolute -translate-x-1/2 -translate-y-1/2 cursor-move rounded-full border-2 shadow-lg hover:scale-110 transition-all z-10"
                                    :class="selectedCorner === index
                                        ? 'border-yellow-300 bg-yellow-400 ring-2 ring-yellow-300 ring-offset-1'
                                        : 'border-white bg-indigo-500'"
                                    :style="`left: ${(corner.x / imageWidth) * 100}%; top: ${(corner.y / imageHeight) * 100}%; width: ${Math.max(16, 24 / zoom)}px; height: ${Math.max(16, 24 / zoom)}px;`"
                                >
                                    <span class="absolute -top-5 left-1/2 -translate-x-1/2 font-bold text-white rounded px-1 py-0.5 whitespace-nowrap pointer-events-none"
                                          :class="selectedCorner === index ? 'bg-yellow-500' : 'bg-indigo-600'"
                                          :style="`font-size: ${Math.max(8, 10 / zoom)}px;`"
                                          x-text="['TL','TR','BR','BL'][index]"></span>
                                </div>
                            </template>
                            <svg class="absolute inset-0 w-full h-full pointer-events-none z-[5]">
                                <template x-for="i in 4">
                                    <line
                                        :x1="`${(corners[(i-1) % 4].x / imageWidth) * 100}%`"
                                        :y1="`${(corners[(i-1) % 4].y / imageHeight) * 100}%`"
                                        :x2="`${(corners[i % 4].x / imageWidth) * 100}%`"
                                        :y2="`${(corners[i % 4].y / imageHeight) * 100}%`"
                                        stroke="rgba(99, 102, 241, 0.8)"
                                        :stroke-width="Math.max(1, 2 / zoom)"
                                        stroke-dasharray="6,3"
                                    />
                                </template>
                            </svg>
                        </div>
                    </div>
                @else
                    <label class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 p-16 cursor-pointer hover:bg-gray-50">
                        <svg class="h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M6.75 7.5h.008v.008H6.75V7.5z" />
                        </svg>
                        <p class="mt-3 text-sm text-gray-600">Upload a room scene image</p>
                        <p class="mt-1 text-xs text-gray-400">MidJourney renders work great here</p>
                        <input type="file" wire:model="backgroundImage" accept="image/*" class="hidden">
                    </label>
                @endif

                {{-- Controls below canvas --}}
                <div class="mt-3 flex items-center gap-4">
                    <label class="flex items-center gap-2 text-xs text-gray-500 cursor-pointer">
                        <input type="checkbox" x-model="showGrid" @change="renderPreview()" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5">
                        Show grid
                    </label>
                    <label class="flex items-center gap-2 text-xs text-gray-500 cursor-pointer">
                        <input type="checkbox" x-model="showPreview" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-3.5 w-3.5">
                        Show preview
                    </label>
                    {{-- Undo / Redo --}}
                    <div class="flex items-center gap-1">
                        <button @click="undo()" :disabled="!canUndo" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed" title="Undo (Ctrl+Z)">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l4-4M3 10l4 4"/></svg>
                        </button>
                        <button @click="redo()" :disabled="!canRedo" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 hover:bg-gray-200 disabled:opacity-30 disabled:cursor-not-allowed" title="Redo (Ctrl+Shift+Z)">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10H11a5 5 0 00-5 5v2M21 10l-4-4M21 10l-4 4"/></svg>
                        </button>
                    </div>
                    {{-- Zoom controls --}}
                    <div class="flex items-center gap-1">
                        <button @click="zoom = Math.max(1, zoom / 1.25); clampPan()" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 hover:bg-gray-200" title="Zoom out (-)">-</button>
                        <span class="text-[10px] text-gray-500 w-10 text-center" x-text="Math.round(zoom * 100) + '%'"></span>
                        <button @click="zoom = Math.min(8, zoom * 1.25); clampPan()" class="rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 hover:bg-gray-200" title="Zoom in (+)">+</button>
                        <button @click="resetZoom()" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 hover:bg-gray-200 ml-1" title="Reset zoom (0)" x-show="zoom > 1">Reset</button>
                        <button @click="zoomToFit()" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500 hover:bg-gray-200" title="Zoom to fit quad (F)">Fit</button>
                    </div>
                    {{-- Corner coordinate inputs --}}
                    <div class="flex gap-2 ml-auto">
                        @foreach(['TL', 'TR', 'BR', 'BL'] as $i => $label)
                            <div
                                @click="selectCorner({{ $i }})"
                                class="rounded px-2 py-1 text-center text-xs cursor-pointer transition-colors"
                                :class="selectedCorner === {{ $i }} ? 'bg-yellow-100 text-yellow-800 ring-1 ring-yellow-300' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'"
                            >
                                {{ $label }}: <span x-text="corners[{{ $i }}]?.x ?? 0"></span>, <span x-text="corners[{{ $i }}]?.y ?? 0"></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Right: Form fields (1 column) --}}
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
                        <option value="kitchen">Kitchen</option>
                        <option value="office">Office</option>
                        <option value="hallway">Hallway</option>
                        <option value="dining-room">Dining Room</option>
                        <option value="minimalist">Minimalist</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Brightness Adjustment ({{ $brightnessAdjust }}%)</label>
                    <input type="range" wire:model.live="brightnessAdjust" min="50" max="150" class="w-full">
                    <div class="flex justify-between text-xs text-gray-400 mt-0.5">
                        <span>Darker</span>
                        <span>Normal</span>
                        <span>Brighter</span>
                    </div>
                </div>

                {{-- Active slot settings --}}
                @if(count($posterSlots) > 0)
                    <div class="rounded-lg bg-gray-50 p-3">
                        <h3 class="text-xs font-semibold text-gray-600 mb-2">Slot: {{ $posterSlots[$activeSlot]['label'] ?? 'Main' }}</h3>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Label</label>
                                <input type="text" wire:model.live="posterSlots.{{ $activeSlot }}.label" class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 mb-1">Aspect Ratio</label>
                                <select wire:model.live="posterSlots.{{ $activeSlot }}.aspect_ratio" class="w-full rounded border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="portrait">Portrait</option>
                                    <option value="landscape">Landscape</option>
                                    <option value="square">Square</option>
                                </select>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Sample poster selector --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Preview Poster</label>
                    @if(count($samplePosters) > 0)
                        <select wire:model.live="samplePosterId" class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">None (show placement area)</option>
                            @foreach($samplePosters as $sp)
                                <option value="{{ $sp->id }}">{{ $sp->title }}</option>
                            @endforeach
                        </select>
                    @else
                        <p class="text-xs text-gray-400">Import posters first to enable live preview</p>
                    @endif
                </div>

                <hr class="border-gray-100">

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Background Image</label>
                    <input type="file" wire:model="backgroundImage" accept="image/*" class="w-full text-sm text-gray-500 file:mr-4 file:rounded file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium">
                    @error('backgroundImage') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Shadow Overlay (optional)</label>
                    <input type="file" wire:model="shadowImage" accept="image/png" class="w-full text-sm text-gray-500 file:mr-4 file:rounded file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium">
                    <p class="mt-1 text-xs text-gray-400">PNG with shadow for realistic depth</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Frame Overlay (optional)</label>
                    <input type="file" wire:model="frameImage" accept="image/png" class="w-full text-sm text-gray-500 file:mr-4 file:rounded file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium">
                    <p class="mt-1 text-xs text-gray-400">Or use built-in frame presets in the Mockup Generator</p>
                </div>

                <button
                    wire:click="saveTemplate"
                    wire:loading.attr="disabled"
                    wire:target="saveTemplate"
                    class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    <x-spinner wire:loading wire:target="saveTemplate" />
                    <span wire:loading.remove wire:target="saveTemplate">{{ $templateId ? 'Update Template' : 'Save Template' }}</span>
                    <span wire:loading wire:target="saveTemplate">Saving...</span>
                </button>
            </div>
        </div>
    </div>
</div>
