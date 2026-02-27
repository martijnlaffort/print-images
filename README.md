# PosterForge

All-in-one desktop app for poster production — AI upscaling, room scene mockups, and batch export. Built with NativePHP (Laravel + Electron).

## Requirements

- **Windows 10** or later
- **PHP 8.3+** (via [Laravel Herd](https://herd.laravel.com/) or standalone)
- **Node.js 18+**
- **Composer**
- **Vulkan-compatible GPU** (NVIDIA/AMD dedicated, some Intel iGPUs)
- **PHP Imagick extension** (for mockup generation)

## Installation

### 1. Clone the repository

```bash
git clone <repo-url> posterforge
cd posterforge
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Install Node dependencies

```bash
npm install
```

### 4. Set up environment

```bash
cp .env.example .env
php artisan key:generate
```

### 5. Create the database

The app uses SQLite. Create the database file and run migrations:

```bash
touch database/database.sqlite
php artisan migrate
```

### 6. Download the Real-ESRGAN binary

The upscaling engine is a standalone binary that ships separately.

1. Go to [Real-ESRGAN NCNN Vulkan Releases](https://github.com/xinntao/Real-ESRGAN-ncnn-vulkan/releases)
2. Download the latest Windows zip (e.g. `realesrgan-ncnn-vulkan-20220424-windows.zip`)
3. Extract and copy these files into `bin/win/`:
   ```
   bin/
   └── win/
       ├── realesrgan-ncnn-vulkan.exe
       └── models/
           ├── realesrgan-x4plus.bin
           └── realesrgan-x4plus.param
   ```

### 7. Enable the Imagick PHP extension

Mockup generation requires the Imagick extension. Herd does not bundle it by default, so you need to install it manually.

#### 7a. Download the correct DLL package

1. Go to [mlocati.github.io/articles/php-windows-imagick.html](https://mlocati.github.io/articles/php-windows-imagick.html)
2. Select your configuration:
   - **PHP version:** match your Herd PHP version (e.g. 8.3)
   - **Architecture:** x64
   - **Thread Safety:** nts (non-thread-safe)
3. Download the zip file

> To check your exact config, run `php -i | findstr /C:"Thread Safety" /C:"Architecture" /C:"PHP Version"`.

#### 7b. Place the files

1. Copy **`php_imagick.dll`** into your Herd PHP ext directory:
   ```
   C:\Users\<username>\.config\herd\bin\php83\ext\
   ```
2. Create a new folder for the ImageMagick DLLs:
   ```
   C:\Users\<username>\.config\herd\bin\php83\imagick\
   ```
3. Extract all **other** DLLs from the zip (`CORE_RL_*.dll`, `IM_MOD_RL_*.dll`, etc.) into that `imagick\` folder

> Replace `php83` with your actual PHP version folder (e.g. `php84`).

#### 7c. Add to Windows PATH

1. Open **Settings → System → About → Advanced system settings → Environment Variables**
2. Under **System variables**, edit `Path`
3. Add this entry near the top:
   ```
   C:\Users\<username>\.config\herd\bin\php83\imagick
   ```

#### 7d. Edit php.ini

In Herd, right-click on your PHP version → **Open php.ini directory**. Add at the bottom of `php.ini`:

```ini
[Imagick]
extension="C:\Users\<username>\.config\herd\bin\php83\ext\php_imagick.dll"
```

#### 7e. Restart and verify

Restart your computer (a full restart, not just Herd), then verify:

```bash
php -m | findstr imagick
```

> Do **not** install ImageMagick separately via the MSI installer — it can cause version conflicts with the DLLs.

### 8. Build frontend assets

```bash
npm run build
```

### 9. Run the app

**As a desktop app (NativePHP/Electron):**

```bash
php artisan native:serve
```

**As a web app (for development):**

```bash
php artisan serve
npm run dev
```

Then open [http://localhost:8000](http://localhost:8000).

## Usage

1. **Import** — Drag and drop poster images onto the Dashboard, or use the Import button
2. **Upscale** — Go to the Upscale tab, select images, pick a scale factor (2x/3x/4x), and start the queue
3. **Templates** — Create room scene templates by uploading a background image and placing four corner points where the poster should appear
4. **Mockups** — Select posters and templates, then generate perspective-correct room mockups
5. **Export** — Pick print sizes (A4, A3, A2, 50x70, 30x40), validate DPI, choose an output folder, and export

## Project Structure

```
app/
├── Jobs/           # UpscaleImage, GenerateMockup, GenerateSizeVariants
├── Livewire/       # Dashboard, UpscaleQueue, MockupGenerator, TemplateEditor, BatchExporter
├── Models/         # Poster, MockupTemplate, GeneratedMockup
└── Services/       # UpscaleService, MockupService, DpiValidator, NamingService
bin/win/            # Real-ESRGAN binary + models (not committed)
config/posterforge.php  # Naming patterns, upscale defaults
```
