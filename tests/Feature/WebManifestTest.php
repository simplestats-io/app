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

it('only references icons that exist', function (): void {
    $icons = $this->get('/site.webmanifest')->json('icons');

    expect($icons)->not->toBeEmpty();

    foreach ($icons as $icon) {
        expect(public_path($icon['src']))->toBeFile();
    }
});
