<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function writeOctaneState(array $state, ?int $processId = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'octane-state');
    file_put_contents($path, json_encode(['masterProcessId' => $processId, 'state' => $state]));
    config(['octane.state_file' => $path]);

    return $path;
}

it('restarts the FrankenPHP workers through the admin API', function (): void {
    writeOctaneState(['adminHost' => 'localhost', 'adminPort' => 2099]);
    Http::fake(['*' => Http::response()]);

    $this->artisan('app:reload-octane')->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://localhost:2099/frankenphp/workers/restart');
});

it('refuses to reload a server that runs a fixed older release', function (): void {
    $release = sys_get_temp_dir().'/octane-release-'.uniqid();
    mkdir($release);
    $server = proc_open(['sleep', '10'], [], $pipes, null, ['APP_BASE_PATH' => $release]);
    writeOctaneState(['adminPort' => 2099], proc_get_status($server)['pid']);
    Http::fake();

    $this->artisan('app:reload-octane')->assertFailed();

    Http::assertNothingSent();
    proc_terminate($server);
})->skip(! is_dir('/proc/self'), 'Reads the server environment from /proc (Linux only).');

it('reloads a server that runs from the current symlink', function (): void {
    $current = sys_get_temp_dir().'/octane-current-'.uniqid();
    symlink(base_path(), $current);
    $server = proc_open(['sleep', '10'], [], $pipes, null, ['APP_BASE_PATH' => $current]);
    writeOctaneState(['adminPort' => 2099], proc_get_status($server)['pid']);
    Http::fake(['*' => Http::response()]);

    $this->artisan('app:reload-octane')->assertSuccessful();

    Http::assertSentCount(1);
    proc_terminate($server);
    unlink($current);
})->skip(! is_dir('/proc/self'), 'Reads the server environment from /proc (Linux only).');

it('fails when no Octane server is running', function (): void {
    config(['octane.state_file' => sys_get_temp_dir().'/missing-octane-state.json']);
    Http::fake();

    $this->artisan('app:reload-octane')->assertFailed();

    Http::assertNothingSent();
});
