<?php

namespace App\Http\Controllers;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class WebManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'id' => '/',
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'start_url' => RouteServiceProvider::HOME,
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#f9fafb',
            'background_color' => '#ffffff',
            // Static files are cached for a year, so the content hash forces browsers
            // to fetch an icon again once it changes.
            'icons' => collect([
                ['src' => '/android-chrome-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/android-chrome-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/maskable-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ])->map(fn (array $icon): array => [
                ...$icon,
                'src' => $icon['src'].'?v='.Str::substr(File::hash(public_path($icon['src'])), 0, 8),
            ])->all(),
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }
}
