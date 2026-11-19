<?php

use App\Models\Project;
use Database\Seeders\TestUserSeeder;

use function Spatie\RouteTesting\routeTesting;

$exclude = [
    '_ignition/*',
    'admin',
    'admin/*',
    'api/v1/stats*', // API routes require Bearer token, tested separately in StatsReadTest
    'email/*',
    'impersonate/*',
    'livewire/*',
    'user-settings/*',
    'team*',
    'stats/*',
    'laravel-favicon/*',
    'oauth/*',
    'spark/*',
    'billing/*',
    'projects/{project}/*', // token-view & token-regenerate routes
    'blog/{slug}', // requires a real slug, tested separately in BlogTest
    'og-image/*', // requires a generated og-image filename
    'docs/api-documentation.html', // permanent redirect to docs/ingest-api.html
    'livewire*',
    'filament/*',
    'user/passkeys/*', // behind password.confirm, covered in PasskeyTest
    'mcp', // MCP streamable-http transport, GET without a session correctly returns 405

    '{locale}', // localized routes, tested in LocalizationTest
    '{locale}/*',
];

routeTesting('all GET routes')
    ->exclude($exclude)
    ->setUp(function (string $method, string $uri): void {
        // if ($uri !== 'user/confirm-password') return; // only for tests

        // public marketing pages redirect to the login on self-hosted
        if (in_array($uri, ['/', 'blog', 'imprint', 'dpa', 'trademark', 'changelog']) || str_starts_with($uri, 'docs/')) {
            $this->skipUnlessSaas();
        }

        $route = resolve('router')->getRoutes()->match(request()->create($uri, $method));

        if (in_array('auth:sanctum', $route->middleware()) || in_array('auth:web', $route->middleware())) {
            $this->seed(TestUserSeeder::class);
            $project = Project::get()->first();
            $this->bind('project', $project);
        }
    })
    ->assertSuccessful();
