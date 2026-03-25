<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopService
{
    private ?string $token = null;

    public function __construct(
        private string $baseUrl,
        private string $email,
        private string $password,
    ) {}

    public static function make(): static
    {
        $url = config('shop.url');
        $email = config('shop.email');
        $password = config('shop.password');

        if (! $url || ! $email || ! $password) {
            throw new RuntimeException('Shop API credentials are not configured. Set SHOP_URL, SHOP_EMAIL, and SHOP_PASSWORD in .env');
        }

        return new static($url, $email, $password);
    }

    public function authenticate(): void
    {
        $response = Http::post("{$this->baseUrl}/api/sanctum/token", [
            'email' => $this->email,
            'password' => $this->password,
            'device_name' => 'PosterForge',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to authenticate with shop API: ' . $response->body());
        }

        $this->token = $response->json('token');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{success: bool, id: int, slug: string}
     */
    public function createProduct(array $data): array
    {
        $response = $this->client()->post("{$this->baseUrl}/api/products", $data);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create product: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * @return array{success: bool, id: int, url: string}
     */
    public function uploadMedia(int $productId, string $filePath, string $collection = 'gallery'): array
    {
        $response = $this->client()
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/api/products/{$productId}/media", [
                'collection' => $collection,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to upload media ({$collection}): " . $response->body());
        }

        return $response->json();
    }

    private function client(): PendingRequest
    {
        if (! $this->token) {
            $this->authenticate();
        }

        return Http::withToken($this->token)->acceptJson();
    }
}
