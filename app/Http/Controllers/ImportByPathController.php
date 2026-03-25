<?php

namespace App\Http\Controllers;

use App\Models\Poster;
use App\Models\PosterActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportByPathController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'paths' => 'required|array|min:1',
            'paths.*' => 'required|string',
        ]);

        $posters = [];
        $skipped = 0;

        foreach ($validated['paths'] as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $poster = Poster::createFromImport($file);

            if ($poster) {
                PosterActivity::log($poster->id, 'imported', ['filename' => basename($file)]);
                $posters[] = ['id' => $poster->id, 'title' => $poster->title];
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'success' => true,
            'posters' => $posters,
            'skipped' => $skipped,
        ]);
    }
}
