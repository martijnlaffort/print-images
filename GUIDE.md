# PosterForge User Guide

Desktop app for your poster webshop: import MidJourney images, upscale to print-ready quality, generate room mockups, and export size variants. Replaces Let's Enhance and Placeit.

## Workflow

**Import** > **Upscale** > **Mockups** > **Export**

Each poster shows its status as a colored badge: imported (gray) > upscaled (blue) > mockups ready (purple) > exported (green).

---

## Dashboard

Import your MidJourney images by dragging files into the drop zone, clicking **Import Files**, or using **Browse Files**. Supports JPG, PNG, and WebP up to 100 MB.

Use **Search** to filter by title. Select posters with checkboxes for batch actions (upscale, mockup, export, or delete).

---

## Upscale

The upscaler automatically figures out how much to enlarge each image based on your target print size and DPI. No need to manually pick a scale factor.

### Quick start

1. Select a **Target Print Size** (e.g. 50x70 cm)
2. Set **Target DPI** (300 is the standard for print)
3. Pick a **preset** or adjust settings manually
4. Select posters and click **Upscale Selected**

### Presets

| Preset | What it does |
|--------|-------------|
| **Standard** | Balanced. Good starting point for most posters. |
| **Detailed** | Preserves fine details and text. More conservative AI processing. |
| **Sharp** | Maximum crispness. Best for bold graphic art. May introduce subtle AI artifacts. |
| **Vivid** | Slightly brighter and more saturated. Gives images extra punch for marketing. |
| **Gentle** | Safest option. Minimal AI processing, very few artifacts, but softer result. |

### Settings explained

- **Target Print Size** / **Target DPI** — The app calculates the exact pixels needed (e.g. 50x70 cm at 300 DPI = 5906 x 8268 px) and runs as many AI upscale passes as needed to reach that resolution.
- **AI Model** — *Photo-realistic* for most MidJourney images; *Illustration/Poster* for anime-style or flat graphic art.
- **Denoise** — Blends AI output with a traditional upscale. Higher = smoother/safer, lower = sharper/more detail. Increase if you see weird textures.
- **Sharpen** — Adds crispness after upscaling. Keep at 10-30% for subtle improvement, or 0% if the result is already sharp enough.
- **Brightness / Contrast / Saturation** — Color correction applied after upscaling. Leave at defaults unless you want to adjust the mood.

### DPI indicators

Each poster shows its current DPI for the selected print size:
- **Green** — Already high enough, no AI upscale needed
- **Yellow** — Acceptable but below target, will be upscaled
- **Red** — Low resolution, significant upscaling needed

### Before/After

After upscaling, click **Compare** on any poster to see a side-by-side slider of original vs. upscaled.

### Progress

Each poster shows a progress bar during upscaling: *upscaling* > *finalizing* > *completed*.

---

## Mockups

Creates marketing images of your poster in room settings.

### Quick start

1. Select posters on the left
2. Configure fit mode, frame style, and format at the top
3. Pick a template on the right and click **Generate** — or click **Generate All** for every template

### Options

- **Fit Mode** — *Fill*: crops to fit the frame (default, no gaps). *Fit*: shows entire poster with white padding. *Stretch*: distorts to fill.
- **Frame Style** — Choose from None, Thin Black, Thin White, Gallery White, Oak Wood, or Dark Wood. Frames are drawn automatically around the poster area.
- **Format** — JPEG (smaller, good for web) or PNG (lossless).
- **Quality** — JPEG compression level, 60-100%. Default 92%.
- **Category filter** — Filter templates by room type.

### Text overlay

Expand "Text Overlay" to add text (e.g. artist name, title, price) on a semi-transparent bar. Configure font size, color, and position (top/bottom/corners).

### Download ZIP

Click **Download ZIP** to bundle all mockups for the selected posters into one archive.

### Multi-poster scenes

If a template has multiple poster slots, the first selected poster fills slot 1, the second fills slot 2, etc. Configure slots in the Template Editor.

---

## Templates

Lists all your mockup templates. Click **New Template** to create one, or **Edit** / **Delete** on existing templates.

---

## Template Editor

Turn your MidJourney room scenes into reusable mockup templates.

### Creating a template

1. Upload a room scene as the **Background Image**
2. Drag the four corner handles (TL, TR, BR, BL) to mark where the poster should appear
3. Use a **preset** for quick positioning (Centered, Large, Angled L/R, Above Sofa)
4. Select a **Preview Poster** to see a live preview of how it looks
5. Set **Name**, **Category**, and **Brightness** to match the room lighting
6. Click **Save Template**

### Multiple poster slots

Click **+ Add Poster Slot** to add additional placement areas for scenes with multiple posters. Each slot has its own corners, label, and aspect ratio. The active slot shows blue handles; inactive slots show as gray outlines.

### Tools

- **Show grid** — Overlays a perspective grid inside the poster area to check alignment
- **Corner presets** — Quick one-click corner layouts
- **Shadow / Frame overlays** — Optional PNGs for realistic depth. Or use the built-in frame presets in the Mockup Generator instead.

---

## Export

Generates print-ready files at specific paper sizes.

1. Select posters and check the print sizes you need (A4, A3, A2, 30x40, 50x70, or custom sizes)
2. Optionally click **Check DPI** to verify quality — green checkmark = good, yellow ~ = acceptable, red X = too low
3. Choose **Output Format** (PNG for print, JPEG for smaller files) and set a **Naming Pattern** with tokens: `{title}`, `{size}`, `{date}`
4. Pick an output folder and click **Export All**
5. Use **Download ZIP** to bundle all exports

---

## Settings

- **Default Output Folder** — Pre-fills the export destination
- **Naming Patterns** — Configure filenames for upscaled images (`{title}`, `{date}`), size variants (`{title}`, `{size}`, `{date}`), and mockups (`{title}`, `{template}`, `{date}`)
- **Print Sizes** — Add custom sizes (name + width/height in cm) that appear throughout the app. Built-in sizes (A4, A3, A2, 30x40, 50x70) cannot be removed.

---

## Requirements

- **Real-ESRGAN binary** — Download from [GitHub](https://github.com/xinntao/Real-ESRGAN-ncnn-vulkan/releases), place in `bin/win/`
- **ImageMagick 7.1.2+** — Installed at `C:\Program Files\ImageMagick-7.1.2-Q16\`
- **GPU recommended** — Real-ESRGAN uses Vulkan for hardware acceleration
