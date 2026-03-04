<?php

namespace App\Jobs;

use App\Models\GeneratedMockup;
use App\Models\MockupTemplate;
use App\Models\Poster;
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

    public int $timeout = 300;

    public function __construct(
        public Poster $poster,
        public MockupTemplate $template,
    ) {}

    public function handle(MockupService $mockupService, NamingService $namingService): void
    {
        $outputFilename = $namingService->mockupName($this->poster->slug, $this->template->slug);
        $outputPath = storage_path('app/mockups/' . $outputFilename);

        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $posterPath = $this->poster->upscaled_path ?? $this->poster->original_path;

        $mockupService->generate(
            posterPath: $posterPath,
            backgroundPath: $this->template->background_path,
            corners: $this->template->corners,
            outputPath: $outputPath,
            options: [
                'shadowPath' => $this->template->shadow_path,
                'framePath' => $this->template->frame_path,
                'brightness' => $this->template->brightness_adjust,
            ],
        );

        GeneratedMockup::create([
            'poster_id' => $this->poster->id,
            'template_id' => $this->template->id,
            'output_path' => $outputPath,
        ]);

        if ($this->poster->status !== 'exported') {
            $this->poster->update(['status' => 'mockups_ready']);
        }
    }
}
