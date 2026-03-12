<?php

use App\Livewire\BatchExporter;
use App\Livewire\Dashboard;
use App\Livewire\MockupGenerator;
use App\Livewire\Settings;
use App\Livewire\TemplateEditor;
use App\Livewire\TemplateList;
use App\Livewire\UpscaleQueue;
use App\Models\Poster;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class);
Route::get('/upscale', UpscaleQueue::class);
Route::get('/mockups', MockupGenerator::class);
Route::get('/templates', TemplateList::class);
Route::get('/templates/create', TemplateEditor::class);
Route::get('/templates/{id}/edit', TemplateEditor::class);
Route::get('/export', BatchExporter::class);
Route::get('/settings', Settings::class);

Route::get('/poster-image/{poster}', function (Poster $poster, Request $request) {
    $type = $request->query('type', 'original'); // original, upscaled, thumbnail
    $path = $type === 'upscaled' && $poster->upscaled_path
        ? $poster->upscaled_path
        : $poster->original_path;

    if (! file_exists($path)) {
        abort(404);
    }

    // For thumbnails, serve a cached resized version
    if ($type === 'thumbnail') {
        $thumbDir = storage_path('app/thumbnails');
        $thumbPath = $thumbDir . '/' . $poster->id . '_thumb.jpg';

        if (! file_exists($thumbPath) || filemtime($thumbPath) < filemtime($path)) {
            if (! is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }

            $imageInfo = @getimagesize($path);
            if ($imageInfo) {
                $srcW = $imageInfo[0];
                $srcH = $imageInfo[1];
                $maxDim = 400;
                $scale = min($maxDim / $srcW, $maxDim / $srcH, 1.0);
                $newW = (int) round($srcW * $scale);
                $newH = (int) round($srcH * $scale);

                $src = match ($imageInfo['mime']) {
                    'image/jpeg' => imagecreatefromjpeg($path),
                    'image/png' => imagecreatefrompng($path),
                    'image/webp' => imagecreatefromwebp($path),
                    default => null,
                };

                if ($src) {
                    $thumb = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
                    imagejpeg($thumb, $thumbPath, 85);
                    imagedestroy($src);
                    imagedestroy($thumb);
                }
            }
        }

        if (file_exists($thumbPath)) {
            $path = $thumbPath;
        }
    }

    $mime = mime_content_type($path);

    return response()->file($path, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('poster.image');

Route::get('/mockup-image/{mockup}', function (\App\Models\GeneratedMockup $mockup) {
    if (! file_exists($mockup->output_path)) {
        abort(404);
    }

    return response()->file($mockup->output_path, [
        'Content-Type' => mime_content_type($mockup->output_path),
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('mockup.image');

Route::get('/mockup-download/{mockup}', function (\App\Models\GeneratedMockup $mockup) {
    if (! file_exists($mockup->output_path)) {
        abort(404);
    }

    return response()->download($mockup->output_path);
})->name('mockup.download');

Route::get('/download-file', function (Request $request) {
    $path = $request->query('path');

    if (! $path || ! file_exists($path) || ! str_starts_with(realpath($path), realpath(storage_path()))) {
        abort(404);
    }

    return response()->download($path)->deleteFileAfterSend();
})->name('file.download');

Route::get('/template-image/{template}', function (\App\Models\MockupTemplate $template, Request $request) {
    if (! file_exists($template->background_path)) {
        abort(404);
    }

    $path = $template->background_path;

    if ($request->query('thumb')) {
        $thumbDir = storage_path('app/thumbnails');
        $thumbPath = $thumbDir . '/template_' . $template->id . '_thumb.jpg';

        if (! file_exists($thumbPath) || filemtime($thumbPath) < filemtime($path)) {
            if (! is_dir($thumbDir)) {
                mkdir($thumbDir, 0755, true);
            }

            $imageInfo = @getimagesize($path);
            if ($imageInfo) {
                $srcW = $imageInfo[0];
                $srcH = $imageInfo[1];
                $maxDim = 400;
                $scale = min($maxDim / $srcW, $maxDim / $srcH, 1.0);
                $newW = (int) round($srcW * $scale);
                $newH = (int) round($srcH * $scale);

                $src = match ($imageInfo['mime']) {
                    'image/jpeg' => imagecreatefromjpeg($path),
                    'image/png' => imagecreatefrompng($path),
                    'image/webp' => imagecreatefromwebp($path),
                    default => null,
                };

                if ($src) {
                    $thumb = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
                    imagejpeg($thumb, $thumbPath, 85);
                    imagedestroy($src);
                    imagedestroy($thumb);
                }
            }
        }

        if (file_exists($thumbPath)) {
            $path = $thumbPath;
        }
    }

    return response()->file($path, [
        'Content-Type' => mime_content_type($path),
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('template.image');
