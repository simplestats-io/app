<?php

beforeEach(function (): void {
    config(['app.app_type' => 'default']);
});

it('sends self-hosted visitors of marketing pages to the login', function (string $uri): void {
    $this->get($uri)->assertRedirect(route('login'));
})->with(['/', '/de', '/imprint', '/de/imprint', '/dpa', '/trademark', '/changelog', '/blog', '/blog/server-side-analytics-laravel']);

it('sends self-hosted docs links to the public docs', function (): void {
    $this->get('/docs/installation.html')->assertRedirect('https://simplestats.io/docs/installation.html');
    $this->get('/docs')->assertRedirect('https://simplestats.io/docs/');
});

it('keeps the marketing pages on the saas', function (): void {
    config(['app.app_type' => 'saas']);

    $this->get('/imprint')->assertOk();
    $this->get('/trademark')->assertOk();
    $this->get('/changelog')->assertOk();
    $this->get('/dpa')->assertOk()->assertSee('Data Processing Agreement');
});
