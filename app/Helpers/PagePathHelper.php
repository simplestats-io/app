<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class PagePathHelper
{
    /**
     * Shared by entry pages and page views so the same page reads identically in both.
     * Case is kept on purpose, entry pages never lowercased and existing data must not move.
     */
    public static function normalize(?string $path): ?string
    {
        if (is_null($path)) {
            return null;
        }

        if ($path !== '/' && Str::endsWith($path, '/')) {
            $path = Str::beforeLast($path, '/');
        }

        if (FileHelper::hasNonExecutableWebFileExtension($path)) {
            return null;
        }

        return $path;
    }
}
