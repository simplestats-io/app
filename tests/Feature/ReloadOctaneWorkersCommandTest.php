<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function writeOctaneState(array $state): string
{
    $path = tempnam(sys_get_temp_dir(), 'octane-state');
    file_put_contents($path, json_encode(['masterProcessId' => 1, 'state' => $state]));
    config(['octane.state_file' => $path]);

    return $path;
}

it('restarts the FrankenPHP workers through the admin API', function (): void {
    writeOctaneState([
        'adminHost' => 'localhost',
        'adminPort' => 2099,
        'octaneConfig' => ['state_file' => base_path('storage/logs/octane-server-state.json')],
    ]);
    Http::fake(['*' => Http::response()]);

    $this->artisan('app:reload-octane')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://localhost:2099/frankenphp/workers/restart');
});

it('refuses to reload a server that runs a fixed older release', function (): void {
    $release = sys_get_temp_dir().'/octane-release-'.uniqid();
    mkdir($release);
    writeOctaneState([
        'adminPort' => 2099,
        'octaneConfig' => ['state_file' => "{$release}/storage/logs/octane-server-state.json"],
    ]);
    Http::fake();

    $this->artisan('app:reload-octane')->assertFailed();

    Http::assertNothingSent();
});

it('fails when no Octane server is running', function (): void {
    config(['octane.state_file' => sys_get_temp_dir().'/missing-octane-state.json']);
    Http::fake();

    $this->artisan('app:reload-octane')->assertFailed();

    Http::assertNothingSent();
});
