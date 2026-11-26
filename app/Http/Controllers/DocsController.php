<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DocsController extends Controller
{
    public function __invoke($path = 'index.html'): Response
    {
        $distPath = base_path('docs/.vitepress/dist/');

        // Raw markdown for AI agents (e.g. the "set up your agent" prompt in the AI chat). Only pages
        // that are part of the built docs are exposed, so no other markdown file in docs/ leaks.
        if (preg_match('/^[a-z0-9-]+\.md$/', (string) $path) && File::exists($distPath.Str::replaceLast('.md', '.html', $path))) {
            return new Response(File::get(base_path('docs/'.$path)), 200, [
                'Content-Type' => 'text/markdown; charset=utf-8',
                // Duplicates the rendered .html page, which is the one that should rank.
                'X-Robots-Tag' => 'noindex',
            ]);
        }

        $filePath = $distPath.$path;

        if (File::extension($filePath) === '' && File::isFile($filePath.'.html')) {
            $filePath .= '.html';
        }

        // The router decodes %2F and %2E, so "../" can reach this point. Only files inside the build are served.
        $filePath = realpath($filePath);

        if ($filePath === false || ! File::isFile($filePath) || ! str_starts_with($filePath, realpath($distPath).DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        $contentType = match (strtolower(File::extension($filePath))) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'woff2' => 'font/woff2',
            'json' => 'application/json',
            default => 'text/html',
        };

        return new Response(File::get($filePath), 200, ['Content-Type' => $contentType]);
    }
}
