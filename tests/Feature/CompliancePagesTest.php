<?php

beforeEach(function (): void {
    $this->skipUnlessSaas();
});

it('renders the compliance and security pages', function (string $uri, string $heading): void {
    $this->get($uri)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('MarkdownPage')->where('content', fn (string $html): bool => str_contains($html, "<h1>{$heading}</h1>")));
})->with([
    ['/compliance', 'Compliance'],
    ['/compliance/gdpr', 'GDPR Compliance'],
    ['/compliance/ccpa', 'CCPA Compliance'],
    ['/compliance/pecr', 'PECR Compliance'],
    ['/security', 'Security'],
]);

it('uses the page meta description instead of the default one', function (): void {
    $this->get('/compliance/ccpa')
        ->assertSee('no selling or sharing of personal information', false)
        ->assertDontSee('Discover which channels actually drive revenue, not just traffic. Server-side analytics', false);
});

it('returns 404 for unknown compliance pages', function (): void {
    $this->get('/compliance/hipaa')->assertNotFound();
    $this->get('/compliance/index')->assertNotFound();
});
