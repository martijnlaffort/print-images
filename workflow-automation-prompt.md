# Poster Production Workflow — Automation Prompt

Paste this into a new Cowork session to continue building the automation.

---

## Context

I have a daily workflow for my poster webshop. I process 10+ items per batch and want to automate as much as possible. The workflow has two manual decision points (topic selection and image selection) — everything else should be automated.

---

## Full Workflow

### Step 1 — 🧑 Choose Topics (Manual Decision)
Claude suggests a list of poster topics/themes. I review them and pick which ones to work with for this batch. Claude should present these in a way that's easy to select from (e.g., numbered list or checkboxes via AskUserQuestion).

### Step 2 — 🤖 Generate Midjourney Prompts (Automated)
Based on my topic selections, Claude generates Midjourney image prompts. These should be optimized for poster-quality output.

### Step 3 — 🤖 Submit to Midjourney (Automated — Browser)
Claude pastes each prompt into Midjourney and submits them. Need to determine: web app vs Discord flow.

### Step 4 — 🧑 Select Best Images (Manual Decision)
Midjourney generates multiple variations per prompt. Claude presents the results and I pick which images to keep for the webshop. Claude should show me the options and let me choose.

### Step 5 — 🤖 Generate Product Copy (Automated)
For each selected image, Claude generates the product listing content:
- Title
- Description
- Meta description
- Any other fields needed for the webshop

**Important:** I already have an existing prompt/template for generating this content. Claude should ask me for it on the first run, then reuse it for subsequent batches. (TODO: paste or link the product copy prompt here before the first session.)

### Step 6 — 🤖 Download & Import into PosterForge (Automated — Browser)
Claude downloads the selected Midjourney images and imports them into PosterForge via the web UI.

### Step 7 — 🤖 Upscale in PosterForge (Automated — Browser)
Claude runs the upscaling queue in PosterForge with the appropriate preset and settings.

### Step 8 — 🤖 Generate Mockups in PosterForge (Automated — Browser)
Claude selects templates and generates room scene mockups for all posters.

### Step 9 — 🤖 Export from PosterForge (Automated — Browser)
Claude exports size variants in the required print formats.

### Step 10 — 🤖 Upload to Webshop (Automated — Browser)
Claude logs into the webshop CMS and uploads:
- The mockup images and/or export files
- The generated title, description, meta description, etc. from step 5

---

## PosterForge Details

- **App name**: PosterForge
- **Location**: `C:\Users\martijn\Development\Herd\print-images`
- **Type**: Laravel + Livewire + NativePHP (Electron)
- **Web UI**: `http://localhost:8000` (use this for automation, not the Electron wrapper)
- **Start command**: `php artisan serve` (web) or `php artisan native:serve` (desktop)
- **All operations are through the Livewire web UI** — no artisan CLI commands for the workflow
- **UI flow**: Dashboard (import) → Upscale tab → Mockups tab → Export tab
- **Batch operations**: Select multiple posters, bulk upscale, generate all mockups, batch export
- **Upscale presets**: Standard, Detailed, Sharp, Vivid, Gentle
- **Upscale engine**: Real-ESRGAN (Vulkan GPU acceleration)
- **Mockup features**: Room scene templates, frame styles (None/Black/White/Oak/Dark Wood), fit modes (Fill/Fit/Stretch), text overlay
- **Export sizes**: A4, A3, A2, 50x70 cm, 30x40 cm, custom
- **Database**: SQLite

---

## What's Needed Before First Run

1. **Walk through each step once** so Claude can observe the exact UI flows in the browser
2. **Start with PosterForge** — open at localhost:8000, do a full import → upscale → mockup → export cycle
3. **Then Midjourney** — show the prompt submission flow (web app or Discord?)
4. **Then the webshop CMS** — show the admin panel upload flow
5. **Provide the product copy prompt** — the existing template for generating titles, descriptions, meta descriptions
6. **Build a skill** that chains all automated steps together
7. **Test with a small batch** (2-3 items) before scaling to 10+
