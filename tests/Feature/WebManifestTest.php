<?php

it('serves the web app manifest with the configured app name', function (): void {
    config(['app.name' => 'Acme Analytics']);

    $this->get('/site.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertJsonPath('name', 'Acme Analytics')
        ->assertJsonPath('start_url', '/dashboard');
});

it('does not start a session for the manifest', function (): void {
    $this->get('/site.webmanifest')->assertCookieMissing(config('session.cookie'));
});

it('references existing icons versioned by their content', function (): void {
    $icons = $this->get('/site.webmanifest')->json('icons');

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        [$path, $version] = explode('?v=', $icon['src']);

        expect(public_path($path))->toBeFile()
            ->and($version)->toBe(substr(md5_file(public_path($path)), 0, 8));
    }
});
