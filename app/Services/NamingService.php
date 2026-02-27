<?php

namespace App\Services;

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
            config('posterforge.naming.upscaled', '{title}_upscaled.png'),
            ['title' => $title]
        );
    }

    public function sizeVariantName(string $title, string $size): string
    {
        return $this->generate(
            config('posterforge.naming.size_variant', '{title}_{size}.png'),
            ['title' => $title, 'size' => $size]
        );
    }

    public function mockupName(string $title, string $template): string
    {
        return $this->generate(
            config('posterforge.naming.mockup', '{title}_mockup_{template}.jpg'),
            ['title' => $title, 'template' => $template]
        );
    }
}
