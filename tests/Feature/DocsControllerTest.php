<?php

use Illuminate\Support\Facades\File;

use function Pest\Laravel\get;

// CI does not build the VitePress docs, so the tests create the files they need and only remove what they created.
beforeEach(function (): void {
    // Self-hosted installs redirect every docs url to the public docs (CheckAppType).
    $this->skipUnlessSaas();

    $this->distPath = base_path('docs/.vitepress/dist');
    $this->createdDist = ! File::isDirectory($this->distPath);
    $this->fixturePath = $this->distPath.'/__test-fixture';

    File::ensureDirectoryExists($this->fixturePath.'/chunks');
    File::put($this->fixturePath.'/page.html', '<p>fixture</p>');
});

afterEach(function (): void {
    if (! isset($this->fixturePath)) {
        return;
    }

    File::deleteDirectory($this->createdDist ? $this->distPath : $this->fixturePath);
});

it('serves a built docs file', function (): void {
    get('/docs/__test-fixture/page.html')
        ->assertOk()
        ->assertSeeHtml('fixture');
});

it('serves a built docs page without the html extension', function (): void {
    get('/docs/__test-fixture/page')->assertOk();
});

it('returns 404 for a directory', function (): void {
    get('/docs/__test-fixture/chunks/')->assertNotFound();
    get('/docs/__test-fixture/chunks')->assertNotFound();
});

it('returns 404 for paths outside the built docs', function (string $path): void {
    get('/docs/'.$path)->assertNotFound();
})->with([
    'encoded slashes' => '..%2F..%2F..%2Fcomposer.json',
    'encoded dots' => '%2e%2e/%2e%2e/%2e%2e/composer.json',
    'plain' => '../../../composer.json',
]);
