<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Str;

class NamingService
{
    public function generate(string $pattern, array $tokens): string
    {
        $result = $pattern;

        foreach ($tokens as $key => $value) {
            $result = str_replace('{' . $key . '}', Str::slug($value), $result);
        }

        return $result;
    }

    public function upscaledName(string $title): string
    {
        return $this->generate(
            Setting::get('naming.upscaled', config('posterforge.naming.upscaled', '{title}_upscaled.png')),
            ['title' => $title, 'date' => now()->format('Y-m-d')]
        );
    }

    public function sizeVariantName(string $title, string $size): string
    {
        return $this->generate(
            Setting::get('naming.size_variant', config('posterforge.naming.size_variant', '{title}_{size}.png')),
            ['title' => $title, 'size' => $size, 'date' => now()->format('Y-m-d')]
        );
    }

    public function mockupName(string $title, string $template): string
    {
        return $this->generate(
            Setting::get('naming.mockup', config('posterforge.naming.mockup', '{title}_mockup_{template}.jpg')),
            ['title' => $title, 'template' => $template, 'date' => now()->format('Y-m-d')]
        );
    }
}
