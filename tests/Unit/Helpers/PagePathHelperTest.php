<?php

use App\Helpers\PagePathHelper;

it('normalizes page paths', function (?string $path, ?string $expected): void {
    expect(PagePathHelper::normalize($path))->toBe($expected);
})->with([
    'root' => ['/', '/'],
    'plain path' => ['/pricing', '/pricing'],
    'trailing slash' => ['/pricing/', '/pricing'],
    'case is kept' => ['/Blog/Hello', '/Blog/Hello'],
    'null' => [null, null],
    'file extension' => ['/report.pdf', null],
    'upper case file extension' => ['/IMAGE.PNG', null],
    'source map' => ['/build/app.js.map', null],
    'dotfile' => ['/.env', null],
    'file in nested directory' => ['/assets/feed.xml', null],
    'file with trailing slash' => ['/deploy.sh/', null],
    'slug ending in json' => ['/daily/accept-json', '/daily/accept-json'],
    'slug ending in sh' => ['/daily/pin-to-a-commit-hash', '/daily/pin-to-a-commit-hash'],
    'slug ending in back' => ['/daily/holding-you-back', '/daily/holding-you-back'],
    'slug ending in conf' => ['/events/laracon-conf', '/events/laracon-conf'],
    'directory with extension like name' => ['/files.zip/overview', '/files.zip/overview'],
]);
