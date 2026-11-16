<?php

namespace App\Helpers;

use Illuminate\Support\Str;

class FileHelper
{
    public static function getFileExtensions4NonWebExecutables(): array
    {
        return [
            // images
            'jpg', 'jpeg', 'png', 'apng', 'gif', 'bmp', 'webp', 'svg', 'tif', 'tiff', 'avif', 'ico',
            // documents
            'css', 'js', 'js.map', 'json', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'xml', 'yaml',
            // media
            'mp3', 'wav', 'flac', 'mp4', 'avi', 'mov', 'mkv', 'webm', 'ogg', 'wmv',
            // archive
            'zip', 'tar', 'gz', '7z', 'rar', 'bak', 'sql', 'tgz',
            // server
            'env', 'ini', 'sh', 'inc', 'exe', 'settings', 'back', 'fileupload', 'conf',
        ];
    }

    /**
     * Matches only a dotted extension of the last path segment, so slugs that merely end in
     * those letters (e.g. "/accept-json", "/commit-hash", "/holding-you-back") stay pages.
     */
    public static function hasNonExecutableWebFileExtension(string $filename): bool
    {
        $lastSegment = Str::lower(Str::afterLast($filename, '/'));
        $extensions = array_map(fn (string $extension): string => '.'.$extension, self::getFileExtensions4NonWebExecutables());

        return Str::endsWith($lastSegment, $extensions);
    }
}
