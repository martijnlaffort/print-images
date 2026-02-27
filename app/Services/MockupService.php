<?php

namespace App\Services;

use Imagick;
use ImagickPixel;

class MockupService
{
    public function generate(
        string $posterPath,
        string $backgroundPath,
        array $corners,
        string $outputPath,
        array $options = []
    ): string {
        $background = new Imagick($backgroundPath);
        $poster = new Imagick($posterPath);

        $canvas = new Imagick();
        $canvas->newImage(
            $background->getImageWidth(),
            $background->getImageHeight(),
            new ImagickPixel('transparent')
        );
        $canvas->setImageFormat('png');

        $canvas->compositeImage($poster, Imagick::COMPOSITE_OVER, 0, 0);

        $posterW = $poster->getImageWidth();
        $posterH = $poster->getImageHeight();

        $controlPoints = [
            0, 0, $corners[0][0], $corners[0][1],
            $posterW, 0, $corners[1][0], $corners[1][1],
            $posterW, $posterH, $corners[2][0], $corners[2][1],
            0, $posterH, $corners[3][0], $corners[3][1],
        ];

        $canvas->setImageVirtualPixelMethod(Imagick::VIRTUALPIXELMETHOD_TRANSPARENT);
        $canvas->setImageMatte(true);
        $canvas->distortImage(Imagick::DISTORTION_PERSPECTIVE, $controlPoints, false);

        if (isset($options['brightness']) && $options['brightness'] !== 100) {
            $canvas->modulateImage($options['brightness'], 100, 100);
        }

        $background->compositeImage($canvas, Imagick::COMPOSITE_OVER, 0, 0);

        if (! empty($options['shadowPath']) && file_exists($options['shadowPath'])) {
            $shadow = new Imagick($options['shadowPath']);
            $background->compositeImage($shadow, Imagick::COMPOSITE_MULTIPLY, 0, 0);
            $shadow->destroy();
        }

        if (! empty($options['framePath']) && file_exists($options['framePath'])) {
            $frame = new Imagick($options['framePath']);
            $background->compositeImage($frame, Imagick::COMPOSITE_OVER, 0, 0);
            $frame->destroy();
        }

        $background->setImageFormat('jpeg');
        $background->setImageCompressionQuality(92);
        $background->writeImage($outputPath);

        $background->destroy();
        $poster->destroy();
        $canvas->destroy();

        return $outputPath;
    }
}
