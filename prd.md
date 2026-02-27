# PosterForge — NativePHP All-in-One Poster Production Tool

## Overview

PosterForge is a desktop application built with **NativePHP (Laravel + Electron)** that combines the entire poster production workflow into one tool. It replaces paid services like LetsEnhance and Placeit by handling AI upscaling, mockup generation, and export — all locally, for free.

The target user is a solo poster shop owner who generates artwork in Midjourney, needs to upscale images to print-ready DPI, create room scene mockups for marketing, and export in multiple standard print sizes.

## Tech Stack

- **App Shell**: NativePHP (nativephp/electron) — wraps Laravel in Electron for desktop
- **Backend**: Laravel 12 with PHP 8.3+
- **Frontend**: Livewire 3 + Alpine.js + Tailwind CSS (via Vite)
- **Upscaling Engine**: Real-ESRGAN NCNN Vulkan — a standalone CLI binary (no Python/CUDA needed), bundled for Windows
- **Image Processing**: PHP Imagick extension (ImageMagick) — for perspective transforms, compositing, resizing
- **Database**: SQLite (local, file-based)
- **File Handling**: Laravel Storage facade for local filesystem

## Architecture

### Core Services

1. **UpscaleService** — Wraps the Real-ESRGAN NCNN Vulkan CLI binary. Uses Laravel's `Process` facade to shell out to the binary. Supports single image and directory-based batch upscaling. The binary accepts `-i input -o output -s scale -n model -f format` arguments.

2. **MockupService** — Uses PHP Imagick to:
   - Load a room scene background image
   - Take the poster image and perspective-distort it using `Imagick::DISTORTION_PERSPECTIVE` with 4 control point pairs (source corners → destination corners on the wall)
   - Composite the distorted poster onto the background
   - Optionally overlay shadow and frame PNG layers
   - Output the final mockup as JPEG

3. **DpiValidator** — Calculates effective DPI for standard print sizes (A4, A3, A2, 50x70cm, 30x40cm) given an image's pixel dimensions. Returns whether the image meets minimum (150) and recommended (300) DPI thresholds.

4. **NamingService** — Generates filenames using configurable token-based patterns. Available tokens: `{title}`, `{style}`, `{size}`, `{template}`, `{date}`, `{index}`. Default patterns:

| File Type | Default Pattern | Example Output |
|-----------|----------------|----------------|
| Upscaled poster | `{title}_upscaled.png` | `botanical-fern_upscaled.png` |
| Size variant | `{title}_{size}.png` | `botanical-fern_A3.png` |
| Mockup | `{title}_mockup_{template}.jpg` | `botanical-fern_mockup_scandinavian-living-room.jpg` |

Titles are derived from the original filename, lowercased and slugified. Users can override patterns in export settings.

### Data Models

- **Poster** — Represents an imported image. Fields: id, title, slug, original_path, upscaled_path, style_category, status (imported/upscaled/mockups_ready/exported), metadata (JSON), timestamps.
- **MockupTemplate** — A room scene template. Fields: id, name, slug, category (living-room/bedroom/office/hallway/minimalist), background_path, shadow_path (nullable), frame_path (nullable), corners (JSON array of 4 [x,y] points), brightness_adjust (default 100), aspect_ratio (portrait/landscape/square), timestamps.
- **GeneratedMockup** — An output mockup. Fields: id, poster_id (FK), template_id (FK), output_path, timestamps.

### NativePHP Integration

- **Window**: Single main window, 1400x900, min 1024x700
- **Dialogs**: Use `Dialog::open()` for file import with image type filters and `Dialog::save()` for export folder selection
- **Notifications**: Use `Notification` facade after batch jobs complete
- **Child Processes**: Use `ChildProcess::start()` for non-blocking upscale operations with alias-based tracking
- **File System**: Full read/write access to user's local filesystem via Storage facade

### Real-ESRGAN Binary Bundling

The Real-ESRGAN NCNN Vulkan binary is a portable C++ executable — no Python, CUDA, or runtime dependencies. Windows binary + model files are bundled with the app:

```
bin/
└── win/
    ├── realesrgan-ncnn-vulkan.exe
    └── models/
        ├── realesrgan-x4plus.bin
        └── realesrgan-x4plus.param
```

CLI usage: `realesrgan-ncnn-vulkan -i input.jpg -o output.png -s 4 -n realesrgan-x4plus -f png`
For batch: pass a directory as `-i` and `-o` instead of file paths.

Binary path resolves to `base_path('bin/win/realesrgan-ncnn-vulkan.exe')`. For distribution, the binary is bundled as Electron `extraResources` (not packed into ASAR).

### Imagick Perspective Distortion (Mockup Core)

The mockup generation uses Imagick's perspective distortion. Key code pattern:

```php
$canvas = new Imagick();
$canvas->newImage($bg->getImageWidth(), $bg->getImageHeight(), new ImagickPixel('transparent'));
$canvas->compositeImage($poster, Imagick::COMPOSITE_OVER, 0, 0);

$controlPoints = [
    0, 0, $corners[0][0], $corners[0][1],           // top-left
    $posterW, 0, $corners[1][0], $corners[1][1],     // top-right
    $posterW, $posterH, $corners[2][0], $corners[2][1], // bottom-right
    0, $posterH, $corners[3][0], $corners[3][1],     // bottom-left
];

$canvas->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
$canvas->setImageMatte(true);
$canvas->distortImage(Imagick::DISTORTION_PERSPECTIVE, $controlPoints, false);

$background->compositeImage($canvas, Imagick::COMPOSITE_OVER, 0, 0);
```

Shadow overlays use `Imagick::COMPOSITE_MULTIPLY`. Frame overlays use `Imagick::COMPOSITE_OVER`.

## Screens

### Screen 1: Dashboard / Import

The main screen and entry point. Shows a grid of all imported posters with visual status indicators.

- Poster grid with thumbnail, title, and status badge (`imported` / `upscaled` / `mockups ready`)
- Drag-and-drop zone (visible when grid is empty, collapsible when populated)
- "Import Files" button (opens native file dialog)
- Bulk action bar: "Upscale Selected", "Generate Mockups", "Export"
- Search/filter by title

### Screen 2: Upscale Queue

Manages the upscaling pipeline.

- List of queued/in-progress/completed upscale jobs
- Per-image progress indicator
- Model selector dropdown (`realesrgan-x4plus` / `realesrgan-x4plus-anime`)
- Scale factor selector (2x / 3x / 4x)
- Before/after preview slider for completed items
- "Start Queue" / "Pause" / "Cancel" controls

### Screen 3: Mockup Generator

Generate and preview room scene mockups.

- Left panel: poster selector (multi-select from catalog)
- Center: large preview of selected poster in selected template
- Right panel: template grid, filterable by category
- "Generate All" button (all selected posters × all templates)
- Individual "Generate" button per template

### Screen 4: Template Editor

Create and edit mockup templates.

- Room scene image upload
- Four draggable corner handles overlaid on the image
- Form fields: name, category, brightness adjustment
- Optional upload fields: shadow overlay, frame overlay
- Live preview with a sample poster applied
- "Save Template" button

### Screen 5: Export

Configure and execute batch exports.

- Poster selector (multi-select)
- Print size checkboxes (A4, A3, A2, 50×70, 30×40)
- DPI validation results displayed per poster × size combination
- Naming pattern input with token reference
- Output folder picker (native dialog)
- "Export All" button with progress indicator

## Design Notes

- Use Livewire 3 for all interactive components (file upload, queue management, previews)
- Use Alpine.js for client-side interactivity (drag-and-drop corner handles in template editor, image previews)
- Use Tailwind CSS for all styling — keep it clean and modern
- SQLite database stored in the app's storage directory
- All image processing happens locally — no external API calls
- Use Laravel queues (sync driver for simplicity, or database driver for better UX) for batch operations
- Use Livewire's `WithFileUploads` trait combined with Alpine.js drop events for drag-and-drop import
- The template editor needs 4 draggable handles (Alpine.js `@mousedown`/`@mousemove`) overlaid on the room scene image — when dragged, they update Livewire properties via `$wire.set()`
- For the Real-ESRGAN binary: during development, download it manually from https://github.com/xinntao/Real-ESRGAN-ncnn-vulkan/releases and place in `bin/win/`. For distribution, it gets bundled as Electron extraResources.

## Standard Print Sizes Reference

| Size | Width (cm) | Height (cm) | Pixels at 300 DPI |
|------|-----------|------------|-------------------|
| A4 | 21.0 | 29.7 | 2480 × 3508 |
| A3 | 29.7 | 42.0 | 3508 × 4960 |
| A2 | 42.0 | 59.4 | 4960 × 7016 |
| 50×70 | 50.0 | 70.0 | 5906 × 8268 |
| 30×40 | 30.0 | 40.0 | 3543 × 4724 |

## System Requirements

- **OS:** Windows 10 or later
- **GPU:** Vulkan-compatible GPU (most NVIDIA/AMD dedicated cards, some Intel iGPUs)
- **RAM:** 4GB minimum, 8GB recommended
- **Disk:** ~500MB for installation (binary + AI models) + working space for images

## Development Phases

### Phase 1: Foundation

- Set up Laravel 12 + NativePHP project scaffolding
- Bundle Real-ESRGAN Windows binary and implement `UpscaleService`
- Build Dashboard/Import screen with drag-and-drop (Livewire)
- Build Upscale Queue screen with progress tracking
- Implement `Poster` model and migrations
- Native file dialog and notification integration

### Phase 2: Mockup Engine

- Implement `MockupService` with Imagick perspective distortion
- Create `MockupTemplate` and `GeneratedMockup` models and migrations
- Build Template Editor screen (visual corner-point placement)
- Build Mockup Generator screen with preview and batch generation
- Add shadow and frame overlay support

### Phase 3: Export & Polish

- Implement `DpiValidator` for all standard print sizes
- Implement size variant generation job
- Implement `NamingService` with configurable token patterns
- Build Export screen with folder picker and batch export
- Add before/after preview slider for upscaled images

### Phase 4: Refinement

- Expand room scene template library
- Brightness/contrast auto-matching for mockup realism
- Poster style categories and filtering (botanical, abstract, vintage, etc.)
- UX polish, error handling, edge case hardening

## What This Project Does NOT Include

- No user authentication or multi-user support (single-user desktop app)
- No cloud storage or sync
- No webshop or e-commerce API integration (export files manually)
- No macOS or Linux builds — Windows only
- No video processing
- No custom AI model training
- No automatic template corner detection (manual placement only)
- No AI image generation — images are created externally (e.g., Midjourney) and imported
