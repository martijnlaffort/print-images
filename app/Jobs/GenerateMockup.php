<?php

namespace App\Jobs;

use App\Models\GeneratedMockup;
use App\Models\MockupTemplate;
use App\Models\Poster;
use App\Models\PosterActivity;
use App\Services\MockupService;
use App\Services\NamingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateMockup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'mockups';

    public int $timeout = 300;

    /**
     * @param Poster|array<Poster> $poster Single poster or array of posters (for multi-slot templates)
     */
    public function __construct(
        public Poster|array $poster,
        public MockupTemplate $template,
        public string $fitMode = 'fill',
        public string $outputFormat = 'jpg',
        public int $outputQuality = 92,
        public string $framePreset = 'none',
        public ?array $textOverlay = null,
        public ?int $backgroundTaskId = null,
    ) {}

    public function handle(MockupService $mockupService, NamingService $namingService): void
    {
        // Normalize to single poster for backward compat
        $primaryPoster = is_array($this->poster) ? $this->poster[0] : $this->poster;
        $posters = is_array($this->poster) ? $this->poster : [$this->poster];

        $ext = $this->outputFormat === 'png' ? 'png' : 'jpg';
        $outputFilename = $namingService->mockupName($primaryPoster->slug, $this->template->slug);
        $outputFilename = preg_replace('/\.\w+$/', ".{$ext}", $outputFilename);
        $outputPath = storage_path('app/mockups/' . $outputFilename);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $slots = $this->template->getAllSlots();

        try {
            // For multi-slot: generate one mockup with multiple posters
            // Start with the first slot using the standard generate method
            $posterPath = $primaryPoster->upscaled_path ?? $primaryPoster->original_path;

            $mockupService->generate(
                posterPath: $posterPath,
                backgroundPath: $this->template->background_path,
                corners: $slots[0]['corners'],
                outputPath: $outputPath,
                options: [
                    'shadowPath' => $this->template->shadow_path,
                    'framePath' => $this->template->frame_path,
                    'brightness' => $this->template->brightness_adjust,
                    'fitMode' => $this->fitMode,
                    'format' => $this->outputFormat,
                    'quality' => $this->outputQuality,
                    'framePreset' => $this->framePreset,
                    'text' => $this->textOverlay,
                ],
            );

            // For additional slots, composite additional posters onto the result
            for ($i = 1; $i < count($slots) && $i < count($posters); $i++) {
                $additionalPosterPath = $posters[$i]->upscaled_path ?? $posters[$i]->original_path;
                $mockupService->generate(
                    posterPath: $additionalPosterPath,
                    backgroundPath: $outputPath,
                    corners: $slots[$i]['corners'],
                    outputPath: $outputPath,
                    options: [
                        'brightness' => $this->template->brightness_adjust,
                        'fitMode' => $this->fitMode,
                        'format' => $this->outputFormat,
                        'quality' => $this->outputQuality,
                        'framePreset' => $this->framePreset,
                    ],
                );
            }
        } catch (\Throwable $e) {
            // Clean up partial output on failure
            @unlink($outputPath);
            throw $e;
        }

        GeneratedMockup::create([
            'poster_id' => $primaryPoster->id,
            'template_id' => $this->template->id,
            'output_path' => $outputPath,
        ]);

        PosterActivity::log($primaryPoster->id, 'mockup_generated', [
            'template' => $this->template->name,
        ]);

        foreach ($posters as $poster) {
            if ($poster->status !== 'exported') {
                $poster->update(['status' => 'mockups_ready']);
            }
        }

        if ($this->backgroundTaskId) {
            \App\Models\BackgroundTask::find($this->backgroundTaskId)
                ?->incrementCompleted();
        }
    }

    public function failed(\Throwable $e): void
    {
        if ($this->backgroundTaskId) {
            \App\Models\BackgroundTask::find($this->backgroundTaskId)
                ?->markFailed($e->getMessage());
        }
    }
}
