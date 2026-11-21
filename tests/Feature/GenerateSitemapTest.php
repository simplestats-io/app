<?php

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->skipUnlessSaas();

    $this->blogPath = storage_path('app/testing/sitemap-blog');
    $this->publicPath = storage_path('app/testing/sitemap-public');
    File::ensureDirectoryExists($this->blogPath);
    File::ensureDirectoryExists($this->publicPath);
    config(['md-blog.path' => $this->blogPath, 'md-blog.cache.enabled' => false]);
    app()->usePublicPath($this->publicPath);

    foreach (['published-post' => 'true', 'draft-post' => 'false'] as $slug => $published) {
        file_put_contents(
            "{$this->blogPath}/{$slug}.md",
            "---\ntitle: \"{$slug}\"\nslug: \"{$slug}\"\ndate: \"2026-10-13\"\nexcerpt: \"Excerpt\"\npublished: {$published}\n---\n\nContent"
        );
    }
});

afterEach(function (): void {
    if (! isset($this->blogPath)) {
        return;
    }

    File::deleteDirectory($this->blogPath);
    File::deleteDirectory($this->publicPath);
});

test('sitemap contains published blog posts and docs pages', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $sitemap = File::get($this->publicPath.'/sitemap.xml');
    $baseUrl = rtrim(config('app.url'), '/');

    expect($sitemap)
        ->toContain("<loc>{$baseUrl}/blog</loc>")
        ->toContain("<loc>{$baseUrl}/blog/published-post</loc>")
        ->toContain("<loc>{$baseUrl}/docs/</loc>")
        ->toContain("<loc>{$baseUrl}/docs/installation.html</loc>")
        ->toContain("<loc>{$baseUrl}/de/imprint</loc>")
        ->toContain("<loc>{$baseUrl}/compliance</loc>")
        ->toContain("<loc>{$baseUrl}/compliance/gdpr</loc>")
        ->toContain("<loc>{$baseUrl}/security</loc>")
        ->not->toContain('/login')
        ->not->toContain('/register')
        ->not->toContain('draft-post')
        ->not->toContain('markdown-examples');
});
