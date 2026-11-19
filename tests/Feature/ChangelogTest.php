<?php

use App\Services\ChangelogService;
use Inertia\Testing\AssertableInertia;

$markdown = <<<'MD'
# Changelog

All notable changes will be documented in this file.

## v1.1.0 - 2026-11-18

### Added

- A new feature.

### What's Changed

* Bump something by @dependabot[bot] in https://github.com/simplestats-io/app/pull/1

### New Contributors

* @someone made their first contribution

**Full Changelog**: https://github.com/simplestats-io/app/compare/v1.0.1...v1.1.0

## v1.0.1 - 2026-11-17

**Full Changelog**: https://github.com/simplestats-io/app/compare/v1.0.0...v1.0.1

## v1.0.0 - 2026-11-16

First release.

MD;

it('parses releases and drops the contributor sections', function () use ($markdown): void {
    $releases = (new ChangelogService)->parse($markdown);

    expect($releases)->toHaveCount(2)
        ->and($releases[0])->toMatchArray(['version' => 'v1.1.0', 'date' => 'November 18, 2026', 'anchor' => 'v1.1.0'])
        ->and($releases[0]['html'])->toContain('A new feature.')
        ->not->toContain("What's Changed")
        ->not->toContain('dependabot')
        ->not->toContain('New Contributors')
        ->not->toContain('Full Changelog')
        ->and($releases[1]['version'])->toBe('v1.0.0')
        ->and($releases[1]['html'])->toContain('First release.');
});

it('parses every release of the real changelog that has content', function (): void {
    $markdown = file_get_contents(base_path('CHANGELOG.md'));

    preg_match_all('/^## /m', $markdown, $headings);
    preg_match_all('/^## .+\R+\*\*Full Changelog\*\*.*\R*(?=^## |\z)/m', $markdown, $emptyReleases);

    expect((new ChangelogService)->parse($markdown))
        ->toHaveCount(count($headings[0]) - count($emptyReleases[0]));
});

it('shows the changelog on the saas', function (): void {
    config(['app.app_type' => 'saas']);

    $this->mock(ChangelogService::class)->shouldReceive('releases')->andReturn([
        ['version' => 'v1.0.0', 'date' => 'November 16, 2026', 'anchor' => 'v1.0.0', 'html' => '<p>First release.</p>'],
    ]);

    $this->get('/changelog')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Changelog')
            ->has('releases.0', fn (AssertableInertia $release): AssertableInertia => $release
                ->hasAll(['version', 'date', 'anchor', 'html'])));
});
