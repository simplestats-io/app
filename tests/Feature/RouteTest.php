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
    'compliance/{page?}', // restricted to known pages, tested in CompliancePagesTest
    'og-image/*', // requires a generated og-image filename
    'docs/api-documentation.html', // permanent redirect to docs/ingest-api.html
    'livewire*',
    'filament/*',
    'user/passkeys/*', // behind password.confirm, covered in PasskeyTest
    'mcp', // MCP streamable-http transport, GET without a session correctly returns 405
    'mcp/oauth/*', // OAuth consent needs a client and PKCE parameters, covered in McpOAuthToolsTest
    'search-console/*', // Google OAuth callback, covered in GscConnectionControllerTest

    '{locale}', // localized routes, tested in LocalizationTest
    '{locale}/*',

    // permanent redirects of the old root docs urls, tested in SeoTest
    ...array_map(fn (string $doc): string => basename($doc, '.md'), glob(__DIR__.'/../../docs/*.md')),
];

routeTesting('all GET routes')
    ->exclude($exclude)
    ->setUp(function (string $method, string $uri): void {
        // if ($uri !== 'user/confirm-password') return; // only for tests

        // public marketing pages redirect to the login on self-hosted
        if (in_array($uri, ['/', 'blog', 'imprint', 'dpa', 'trademark', 'security', 'changelog']) || str_starts_with($uri, 'docs/')) {
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
