<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class StorageWidget extends Component
{
    public function getStorageDataProperty(): array
    {
        return Cache::remember('storage_widget_data', 300, function () {
            $dirs = [
                'originals' => storage_path('app/originals'),
                'upscaled' => storage_path('app/upscaled'),
                'mockups' => storage_path('app/mockups'),
                'thumbnails' => storage_path('app/thumbnails'),
            ];

            $sizes = [];
            foreach ($dirs as $key => $dir) {
                $sizes[$key] = is_dir($dir) ? $this->directorySize($dir) : 0;
            }

            return $sizes;
        });
    }

    public function recalculate(): void
    {
        Cache::forget('storage_widget_data');
        $this->dispatch('toast', type: 'success', message: 'Storage data refreshed.');
    }

    public function cleanupThumbnails(): void
    {
        $dir = storage_path('app/thumbnails');
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        Cache::forget('storage_widget_data');
        $this->dispatch('toast', type: 'success', message: 'Thumbnails cleared.');
    }

    private function directorySize(string $dir): int
    {
        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    public function render()
    {
        $data = $this->storageData;
        $total = array_sum($data);

        $categories = [
            ['key' => 'originals', 'label' => 'Originals', 'color' => 'bg-blue-500', 'size' => $data['originals']],
            ['key' => 'upscaled', 'label' => 'Upscaled', 'color' => 'bg-indigo-500', 'size' => $data['upscaled']],
            ['key' => 'mockups', 'label' => 'Mockups', 'color' => 'bg-purple-500', 'size' => $data['mockups']],
            ['key' => 'thumbnails', 'label' => 'Thumbnails', 'color' => 'bg-gray-400', 'size' => $data['thumbnails']],
        ];

        foreach ($categories as &$cat) {
            $cat['formatted'] = $this->formatBytes($cat['size']);
            $cat['percent'] = $total > 0 ? round($cat['size'] / $total * 100, 1) : 0;
        }

        return view('livewire.storage-widget', [
            'categories' => $categories,
            'totalFormatted' => $this->formatBytes($total),
        ]);
    }
}
