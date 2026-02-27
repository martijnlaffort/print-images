# PosterForge — NativePHP All-in-One Poster Production Tool

## Technical Blueprint & Architecture

---

## 1. What We're Building

A desktop application built with **NativePHP (Laravel + Electron)** that combines three core poster production steps into one streamlined tool:

1. **AI Upscaling** — Enhance Midjourney images to print-ready resolution using Real-ESRGAN
2. **Mockup Generation** — Place posters into room scene templates with perspective-correct compositing
3. **Export & Organization** — Batch output with smart naming, DPI validation, and multiple size variants

The goal: replace LetsEnhance (€9-29/mo) and Placeit (€9-15/mo) subscriptions with a free, self-hosted tool tailored to your exact workflow.

---

## 2. Tech Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **App Shell** | NativePHP + Electron | Desktop app packaging, native OS integration |
| **Backend** | Laravel 12 | Routing, controllers, queue jobs, file management |
| **Frontend** | Livewire 3 + Alpine.js + Tailwind CSS | Reactive UI, drag-and-drop, real-time progress |
| **Upscaling Engine** | Real-ESRGAN NCNN Vulkan (binary) | AI-powered image upscaling via CLI |
| **Image Processing** | PHP Imagick (ImageMagick) | Perspective transforms, compositing, mockup generation |
| **Database** | SQLite | Local poster catalog, template configs, job history |
| **File System** | Laravel Storage facade | Reading/writing images on the user's machine |

---

## 3. Application Architecture

### 3.1 High-Level Flow

```
┌─────────────────────────────────────────────────────┐
│                    NativePHP (Electron)              │
│                                                     │
│  ┌──────────┐   ┌──────────┐   ┌────────────────┐  │
│  │  Import   │──▶│ Upscale  │──▶│ Generate       │  │
│  │  Screen   │   │  Queue   │   │ Mockups        │  │
│  └──────────┘   └──────────┘   └────────────────┘  │
│       │              │                │              │
│       │              ▼                ▼              │
│       │        ┌──────────┐   ┌────────────────┐   │
│       │        │Real-ESRGAN│   │ Imagick        │   │
│       │        │ CLI Binary│   │ Perspective    │   │
│       │        └──────────┘   │ + Composite    │   │
│       │                       └────────────────┘   │
│       │                              │              │
│       │                              ▼              │
│       │                       ┌────────────────┐   │
│       │                       │ Export          │   │
│       └──────────────────────▶│ & Organize     │   │
│                               └────────────────┘   │
└─────────────────────────────────────────────────────┘
```

### 3.2 Directory Structure

```
poster-forge/
├── app/
│   ├── Http/Controllers/
│   │   ├── ImportController.php        # File upload & import
│   │   ├── UpscaleController.php       # Upscaling job management
│   │   ├── MockupController.php        # Mockup generation
│   │   ├── TemplateController.php      # Room template management
│   │   └── ExportController.php        # Export & batch naming
│   │
│   ├── Livewire/
│   │   ├── Dashboard.php               # Main app screen
│   │   ├── ImageImporter.php           # Drag-and-drop import
│   │   ├── UpscaleQueue.php            # Upscale progress & queue
│   │   ├── MockupPreview.php           # Mockup preview & generation
│   │   ├── TemplateEditor.php          # Visual corner-point editor
│   │   └── BatchExporter.php           # Export configuration
│   │
│   ├── Jobs/
│   │   ├── UpscaleImage.php            # Queue job: run Real-ESRGAN
│   │   ├── GenerateMockup.php          # Queue job: create mockup
│   │   └── GenerateSizeVariants.php    # Queue job: resize to A3/A4/50x70
│   │
│   ├── Services/
│   │   ├── UpscaleService.php          # Real-ESRGAN CLI wrapper
│   │   ├── MockupService.php           # Imagick perspective + composite
│   │   ├── DpiValidator.php            # Check print-readiness
│   │   └── NamingService.php           # SKU/filename conventions
│   │
│   ├── Models/
│   │   ├── Poster.php                  # Poster record
│   │   ├── MockupTemplate.php          # Room scene template
│   │   └── GeneratedMockup.php         # Output mockup record
│   │
│   └── NativePHP/
│       └── MainWindow.php              # NativePHP window config
│
├── resources/
│   ├── views/livewire/                 # Blade + Livewire templates
│   ├── templates/                      # Room scene images
│   │   ├── living-room-1/
│   │   │   ├── background.jpg          # The room photo
│   │   │   ├── shadow-overlay.png      # Optional shadow layer
│   │   │   └── frame-overlay.png       # Optional frame layer
│   │   └── bedroom-1/
│   │       └── ...
│   └── frames/                         # Frame PNGs (white, black, wood, etc.)
│
├── bin/                                # Bundled binaries
│   ├── mac/
│   │   └── realesrgan-ncnn-vulkan
│   ├── win/
│   │   └── realesrgan-ncnn-vulkan.exe
│   └── linux/
│       └── realesrgan-ncnn-vulkan
│
├── database/
│   └── database.sqlite
│
└── config/
    └── posterforge.php                 # App configuration
```

---

## 4. Core Feature Implementation

### 4.1 AI Upscaling (Real-ESRGAN Integration)

The Real-ESRGAN NCNN Vulkan binary is a standalone CLI tool. It requires no Python, no CUDA — just a Vulkan-compatible GPU. The binary is bundled with the app for each platform.

**CLI usage:**
```bash
realesrgan-ncnn-vulkan \
  -i input.jpg \
  -o output.png \
  -s 4 \                    # Scale factor (2, 3, or 4)
  -n realesrgan-x4plus \    # Model name
  -f png \                  # Output format
  -t 0                      # Tile size (0 = auto)
```

**UpscaleService.php — Laravel wrapper:**
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class UpscaleService
{
    public function upscale(
        string $inputPath,
        string $outputPath,
        int $scale = 4,
        string $model = 'realesrgan-x4plus'
    ): string {
        $binary = $this->getBinaryPath();

        $result = Process::timeout(300)->run([
            $binary,
            '-i', $inputPath,
            '-o', $outputPath,
            '-s', (string) $scale,
            '-n', $model,
            '-f', 'png',
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                "Upscale failed: " . $result->errorOutput()
            );
        }

        return $outputPath;
    }

    public function batchUpscale(string $inputDir, string $outputDir, int $scale = 4): string
    {
        // Real-ESRGAN natively supports directory input/output
        $binary = $this->getBinaryPath();

        $result = Process::timeout(3600)->run([
            $binary,
            '-i', $inputDir,
            '-o', $outputDir,
            '-s', (string) $scale,
            '-n', 'realesrgan-x4plus',
            '-f', 'png',
        ]);

        if ($result->failed()) {
            throw new RuntimeException(
                "Batch upscale failed: " . $result->errorOutput()
            );
        }

        return $outputDir;
    }

    private function getBinaryPath(): string
    {
        $platform = PHP_OS_FAMILY;

        return match ($platform) {
            'Darwin' => base_path('bin/mac/realesrgan-ncnn-vulkan'),
            'Windows' => base_path('bin/win/realesrgan-ncnn-vulkan.exe'),
            'Linux' => base_path('bin/linux/realesrgan-ncnn-vulkan'),
            default => throw new RuntimeException("Unsupported platform: {$platform}"),
        };
    }
}
```

**Using NativePHP ChildProcess for non-blocking upscaling:**
```php
use Native\Desktop\Facades\ChildProcess;

// Start upscaling as a background process with progress
ChildProcess::start(
    cmd: "{$binary} -i {$inputDir} -o {$outputDir} -s 4 -n realesrgan-x4plus -f png",
    alias: 'upscale-batch'
);

// Listen for completion
Event::listen(ProcessExited::class, function ($event) {
    if ($event->alias === 'upscale-batch') {
        // Notify UI that upscaling is done
        Notification::title('Upscaling Complete')
            ->message('All images have been upscaled successfully.')
            ->show();
    }
});
```

### 4.2 Mockup Generation (PHP Imagick)

This is the core innovation — using PHP's Imagick extension (which wraps ImageMagick) to do perspective transforms and compositing entirely in PHP. No external Python scripts needed.

**How it works:**

1. Load the room scene (background)
2. Load the poster image
3. Define 4 destination corner points (where the poster sits on the wall)
4. Perspective-distort the poster to match those corners
5. Composite the distorted poster onto the room scene
6. Optionally overlay shadow and frame layers

**MockupService.php:**
```php
<?php

namespace App\Services;

use Imagick;
use ImagickPixel;

class MockupService
{
    /**
     * Generate a single mockup image.
     *
     * @param string $posterPath      Path to the poster image
     * @param string $backgroundPath  Path to the room scene photo
     * @param array  $corners         4 destination points: [[x1,y1], [x2,y2], [x3,y3], [x4,y4]]
     *                                Order: top-left, top-right, bottom-right, bottom-left
     * @param string $outputPath      Where to save the result
     * @param array  $options         Optional: shadowPath, framePath, brightness adjust
     */
    public function generate(
        string $posterPath,
        string $backgroundPath,
        array $corners,
        string $outputPath,
        array $options = []
    ): string {
        // Load images
        $background = new Imagick($backgroundPath);
        $poster = new Imagick($posterPath);

        // Create a canvas the same size as the background
        // (needed so the perspective transform has room to work)
        $canvas = new Imagick();
        $canvas->newImage(
            $background->getImageWidth(),
            $background->getImageHeight(),
            new ImagickPixel('transparent')
        );
        $canvas->setImageFormat('png');

        // Composite the poster onto the canvas at origin
        $canvas->compositeImage($poster, Imagick::COMPOSITE_OVER, 0, 0);

        // Build the perspective distortion control points
        // Format: src_x, src_y, dest_x, dest_y (repeated for 4 corners)
        $posterW = $poster->getImageWidth();
        $posterH = $poster->getImageHeight();

        $controlPoints = [
            // Top-left
            0, 0, $corners[0][0], $corners[0][1],
            // Top-right
            $posterW, 0, $corners[1][0], $corners[1][1],
            // Bottom-right
            $posterW, $posterH, $corners[2][0], $corners[2][1],
            // Bottom-left
            0, $posterH, $corners[3][0], $corners[3][1],
        ];

        // Set virtual pixel method so areas outside the distort are transparent
        $canvas->setImageVirtualPixelMethod(
            Imagick::VIRTUALPIXELMETHOD_TRANSPARENT
        );
        $canvas->setImageMatte(true);

        // Apply perspective distortion
        $canvas->distortImage(
            Imagick::DISTORTION_PERSPECTIVE,
            $controlPoints,
            false  // false = keep original canvas size
        );

        // Optional: adjust poster brightness to match room lighting
        if (isset($options['brightness'])) {
            $canvas->modulateImage(
                $options['brightness'], // brightness (100 = no change)
                100,                    // saturation
                100                     // hue
            );
        }

        // Composite the distorted poster BEHIND the background
        // (if background has transparent areas where the poster should show)
        // OR composite poster ON TOP then overlay shadow
        $background->compositeImage($canvas, Imagick::COMPOSITE_DSTOVER, 0, 0);

        // Alternative approach: poster on top, then shadows
        // $background->compositeImage($canvas, Imagick::COMPOSITE_OVER, 0, 0);

        // Optional: overlay shadow layer
        if (isset($options['shadowPath'])) {
            $shadow = new Imagick($options['shadowPath']);
            $background->compositeImage(
                $shadow,
                Imagick::COMPOSITE_MULTIPLY,
                0, 0
            );
            $shadow->destroy();
        }

        // Optional: overlay frame
        if (isset($options['framePath'])) {
            $frame = new Imagick($options['framePath']);
            $background->compositeImage(
                $frame,
                Imagick::COMPOSITE_OVER,
                0, 0
            );
            $frame->destroy();
        }

        // Save result
        $background->setImageFormat('jpeg');
        $background->setImageCompressionQuality(92);
        $background->writeImage($outputPath);

        // Cleanup
        $background->destroy();
        $poster->destroy();
        $canvas->destroy();

        return $outputPath;
    }

    /**
     * Generate mockups across ALL templates for a single poster.
     */
    public function generateAll(string $posterPath, array $templates): array
    {
        $outputs = [];

        foreach ($templates as $template) {
            $outputPath = storage_path(
                'app/mockups/' .
                pathinfo($posterPath, PATHINFO_FILENAME) . '_' .
                $template->slug . '.jpg'
            );

            $this->generate(
                posterPath: $posterPath,
                backgroundPath: $template->background_path,
                corners: $template->corners,
                outputPath: $outputPath,
                options: [
                    'shadowPath' => $template->shadow_path,
                    'framePath' => $template->frame_path,
                    'brightness' => $template->brightness_adjust ?? 100,
                ]
            );

            $outputs[] = $outputPath;
        }

        return $outputs;
    }
}
```

### 4.3 Template Configuration (Mockup Templates)

Each room scene template is stored as a model with its corner coordinates.

**MockupTemplate model / migration:**
```php
// Migration
Schema::create('mockup_templates', function (Blueprint $table) {
    $table->id();
    $table->string('name');                    // "Modern Living Room"
    $table->string('slug');                    // "modern-living-room"
    $table->string('category');                // "living-room", "bedroom", "office"
    $table->string('background_path');         // Path to room scene image
    $table->string('shadow_path')->nullable(); // Optional shadow overlay
    $table->string('frame_path')->nullable();  // Optional frame overlay
    $table->json('corners');                   // [[x1,y1],[x2,y2],[x3,y3],[x4,y4]]
    $table->integer('brightness_adjust')->default(100);
    $table->string('aspect_ratio');            // "portrait", "landscape", "square"
    $table->timestamps();
});
```

**Visual Template Editor (Livewire component):**

The key UX feature: a drag-and-drop editor where you load a room photo and place four handles on the wall where the poster should appear. This eliminates guessing pixel coordinates.

```php
// TemplateEditor.php (Livewire component)
class TemplateEditor extends Component
{
    use WithFileUploads;

    public $backgroundImage;
    public $name = '';
    public $category = 'living-room';
    public $corners = [
        ['x' => 100, 'y' => 100],  // Top-left
        ['x' => 400, 'y' => 100],  // Top-right
        ['x' => 400, 'y' => 500],  // Bottom-right
        ['x' => 100, 'y' => 500],  // Bottom-left
    ];

    public function saveTemplate()
    {
        // Store background image
        $path = $this->backgroundImage->store('templates', 'local');

        MockupTemplate::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'category' => $this->category,
            'background_path' => storage_path('app/' . $path),
            'corners' => $this->corners,
            'aspect_ratio' => $this->detectAspectRatio(),
        ]);
    }
}
```

The Blade/Alpine.js view would render the room image with four draggable dots that update the `$corners` property via `wire:model`. This is straightforward with Alpine's `@mousedown`/`@mousemove` event handling.

### 4.4 DPI Validation

Before exporting, verify images meet print requirements:

```php
class DpiValidator
{
    /**
     * Standard poster sizes in cm and required pixels at target DPI.
     */
    const SIZES = [
        'A4' => ['width_cm' => 21.0, 'height_cm' => 29.7],
        'A3' => ['width_cm' => 29.7, 'height_cm' => 42.0],
        'A2' => ['width_cm' => 42.0, 'height_cm' => 59.4],
        '50x70' => ['width_cm' => 50.0, 'height_cm' => 70.0],
        '30x40' => ['width_cm' => 30.0, 'height_cm' => 40.0],
    ];

    /**
     * Check if an image meets the minimum DPI for a given print size.
     */
    public function validate(string $imagePath, string $size, int $minDpi = 150): array
    {
        $image = new Imagick($imagePath);
        $pixelW = $image->getImageWidth();
        $pixelH = $image->getImageHeight();
        $image->destroy();

        $sizeSpec = self::SIZES[$size];

        // Calculate effective DPI
        $dpiW = $pixelW / ($sizeSpec['width_cm'] / 2.54);
        $dpiH = $pixelH / ($sizeSpec['height_cm'] / 2.54);
        $effectiveDpi = min($dpiW, $dpiH);

        return [
            'size' => $size,
            'pixel_width' => $pixelW,
            'pixel_height' => $pixelH,
            'effective_dpi' => round($effectiveDpi),
            'meets_minimum' => $effectiveDpi >= $minDpi,
            'recommended_dpi' => 300,
            'meets_recommended' => $effectiveDpi >= 300,
        ];
    }

    /**
     * Check all standard sizes and return which ones are viable.
     */
    public function validateAll(string $imagePath, int $minDpi = 150): array
    {
        $results = [];
        foreach (array_keys(self::SIZES) as $size) {
            $results[$size] = $this->validate($imagePath, $size, $minDpi);
        }
        return $results;
    }
}
```

### 4.5 Size Variant Generation

Resize posters to standard print sizes while maintaining aspect ratio:

```php
class GenerateSizeVariants implements ShouldQueue
{
    public function handle(Poster $poster)
    {
        $sizes = [
            'A4' => ['width' => 2480, 'height' => 3508],   // 300 DPI
            'A3' => ['width' => 3508, 'height' => 4960],   // 300 DPI
            '50x70' => ['width' => 5906, 'height' => 8268], // 300 DPI
            '30x40' => ['width' => 3543, 'height' => 4724], // 300 DPI
        ];

        foreach ($sizes as $sizeName => $dimensions) {
            $image = new Imagick($poster->upscaled_path);

            // Resize to fit within dimensions (maintaining aspect ratio)
            $image->resizeImage(
                $dimensions['width'],
                $dimensions['height'],
                Imagick::FILTER_LANCZOS,
                1,
                true  // bestfit
            );

            // Set DPI metadata
            $image->setImageResolution(300, 300);
            $image->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);

            $outputPath = storage_path(
                "app/exports/{$poster->slug}_{$sizeName}.png"
            );
            $image->writeImage($outputPath);
            $image->destroy();
        }
    }
}
```

---

## 5. NativePHP Desktop Integration

### 5.1 Window Configuration

```php
// app/NativePHP/MainWindow.php
use Native\Laravel\Facades\Window;

class MainWindow
{
    public function boot()
    {
        Window::open()
            ->title('PosterForge')
            ->width(1400)
            ->height(900)
            ->minWidth(1024)
            ->minHeight(700);
    }
}
```

### 5.2 Native Dialogs for File Import

```php
use Native\Laravel\Facades\Dialog;

// Open file dialog for importing Midjourney images
public function importImages()
{
    $files = Dialog::open('Select poster images')
        ->filter('Images', ['jpg', 'jpeg', 'png', 'webp'])
        ->multiple()
        ->asSheet();

    foreach ($files as $file) {
        // Copy to app storage and create Poster record
        $poster = Poster::createFromImport($file);
    }
}
```

### 5.3 Native Notifications

```php
use Native\Laravel\Facades\Notification;

// After batch processing completes
Notification::title('Batch Complete')
    ->message("12 posters upscaled, 120 mockups generated")
    ->show();
```

---

## 6. UI / Screens

### Screen 1: Dashboard / Import
- Grid view of all imported posters with status badges (imported / upscaled / mockups ready)
- Drag-and-drop zone for importing new images
- Quick actions: "Upscale All", "Generate Mockups", "Export"

### Screen 2: Upscale Queue
- List of images being processed
- Progress indicator per image (using ChildProcess STDOUT monitoring)
- Model selection (realesrgan-x4plus for general, realesrgan-x4plus-anime for illustration styles)
- Scale factor selector (2x, 3x, 4x)
- Before/after preview slider

### Screen 3: Mockup Generator
- Left panel: select poster(s) to mock up
- Center: preview of currently selected mockup template with poster applied
- Right panel: grid of all available templates, filterable by category
- Batch action: "Generate all templates for selected posters"

### Screen 4: Template Editor
- Upload a room scene photo
- Drag four corner handles onto the wall area
- Optional: upload shadow overlay and frame overlay
- Set brightness adjustment and metadata
- Preview with a sample poster before saving

### Screen 5: Export
- Select posters and desired output formats
- Choose print sizes (A4, A3, 50x70cm, etc.)
- DPI validation results shown per size
- File naming pattern (e.g., `{style}-{title}-{size}.png`)
- Output folder picker via native dialog
- "Export All" button

---

## 7. Bundling & Distribution

### 7.1 Bundling the Real-ESRGAN Binary

The Real-ESRGAN NCNN Vulkan binary + model files need to be packaged with the Electron app. Since NativePHP uses Electron, you'll place them in the `resources` directory:

```
bin/
├── mac/
│   ├── realesrgan-ncnn-vulkan         # macOS binary
│   └── models/
│       ├── realesrgan-x4plus.bin
│       └── realesrgan-x4plus.param
├── win/
│   ├── realesrgan-ncnn-vulkan.exe     # Windows binary
│   └── models/
│       └── ...
└── linux/
    ├── realesrgan-ncnn-vulkan         # Linux binary
    └── models/
        └── ...
```

In your Electron packaging config, mark these as `extraResources` so they're not packed into the ASAR archive (binaries must be accessible as real files).

### 7.2 Building for Distribution

```bash
# Build for current platform
php artisan native:build

# Build for specific platform
php artisan native:build --platform=mac
php artisan native:build --platform=windows
php artisan native:build --platform=linux
```

### 7.3 System Requirements

- **GPU**: Vulkan-compatible GPU (most dedicated NVIDIA/AMD cards, some Intel iGPUs)
- **RAM**: 4GB minimum, 8GB recommended
- **Disk**: ~500MB for the app (binaries + models), plus working space for images
- **OS**: Windows 10+, macOS 11+, Linux (Ubuntu 20.04+)

---

## 8. Development Roadmap

### Phase 1: Foundation (Week 1-2)
- [ ] Set up Laravel + NativePHP project
- [ ] Integrate Real-ESRGAN binary with UpscaleService
- [ ] Build basic import screen with drag-and-drop (Livewire)
- [ ] Build upscale queue with progress tracking
- [ ] Test on macOS (your primary dev machine)

### Phase 2: Mockup Engine (Week 2-3)
- [ ] Implement MockupService with Imagick perspective distortion
- [ ] Create 5 initial room scene templates (living room, bedroom, office, hallway, minimalist)
- [ ] Build Template Editor (visual corner-point placement)
- [ ] Add shadow and frame overlay support
- [ ] Batch mockup generation across all templates

### Phase 3: Export & Polish (Week 3-4)
- [ ] DPI validation for all standard print sizes
- [ ] Size variant generation (A4, A3, 50x70, 30x40)
- [ ] Smart naming system with configurable patterns
- [ ] Export to chosen folder with native file dialog
- [ ] Before/after preview for upscaled images

### Phase 4: Refinement (Week 4+)
- [ ] Add 10+ additional room scene templates
- [ ] Brightness/contrast auto-matching for mockups
- [ ] Windows build testing and binary bundling
- [ ] Add poster style categories (botanical, abstract, vintage, etc.)
- [ ] Optional: integration with your webshop (upload via API)

---

## 9. Template Preparation Guide

### Creating High-Quality Room Scene Templates

For each template, you need:

1. **Background image** (the room photo)
   - Source: take your own photos OR use royalty-free stock from Unsplash/Pexels
   - Resolution: at least 3000x2000px
   - Choose rooms that appeal to your target audience (feminine aesthetics, Scandinavian style, boho, minimalist)
   - Ensure the wall area where the poster will go is clearly visible and relatively flat

2. **Corner coordinates** (4 points)
   - Use the Template Editor in the app to set these visually
   - Or measure in any image editor (Photoshop, GIMP, Figma)
   - Points define the quadrilateral where the poster will be placed

3. **Shadow overlay** (optional, but adds realism)
   - A semi-transparent PNG the same size as the background
   - Dark gradient along the poster edges simulating wall shadow
   - Can be created in Photoshop/GIMP once and reused with adjustments

4. **Frame overlay** (optional)
   - A PNG of a picture frame, positioned over the poster area
   - Transparent everywhere except the frame itself
   - Prepare variants: thin white frame, black frame, natural wood frame

### Recommended Template Categories for Your Audience

Based on your target market (women, soft aesthetics, trending styles):

| Category | Room Style | Mood |
|----------|-----------|------|
| Scandinavian Living Room | White walls, light wood, soft textiles | Clean, calm |
| Boho Bedroom | Earth tones, rattan, dried flowers | Warm, textured |
| Minimalist Hallway | White/grey, single poster focus | Gallery feel |
| Modern Office | Light desk, plants, natural light | Professional |
| Cozy Reading Nook | Armchair, warm lighting, books | Intimate |
| Gallery Wall | Multiple poster positions | Versatile |

---

## 10. Cost & Time Comparison

### Current Workflow (monthly)
| Service | Cost |
|---------|------|
| LetsEnhance | ~€9-29/mo |
| Placeit | ~€9-15/mo |
| **Total** | **€18-44/mo** |

### PosterForge (one-time build)
| Item | Cost |
|------|------|
| Development time | ~3-4 weeks |
| Real-ESRGAN | Free (open source) |
| ImageMagick/Imagick | Free (open source) |
| NativePHP | Free (open source) |
| Room scene photos | Free (Unsplash/Pexels) or your own |
| **Ongoing cost** | **€0/mo** |

**Break-even: 1-2 months** (after which it's pure savings, plus a custom tool tailored to your exact needs).
