<?php

use App\Mcp\Servers\SimpleStatsServer;
use App\Mcp\Tools\SearchDocs;
use App\Models\User;
use App\Services\Ai\DocsSearchService;
use Database\Seeders\TestUserSeeder;

beforeEach(function (): void {
    $this->seed(TestUserSeeder::class);
    $this->user = User::where('email', config('app.test.user')['email'])->firstOrFail();
});

it('finds the payment tracking docs page', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(SearchDocs::class, ['query' => 'track a new payment'])
        ->assertOk()
        ->assertSee('how-to-track-a-new-payment')
        ->assertStructuredContent(function ($json): void {
            $json->has('results.0.title')
                ->has('results.0.url')
                ->has('results.0.excerpt')
                ->etc();
        });
});

it('notes when nothing matches', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(SearchDocs::class, ['query' => 'xyzzyplugh'])
        ->assertOk()
        ->assertSee('No documentation pages matched');
});

it('rejects an empty query', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(SearchDocs::class, [])
        ->assertHasErrors();
});

it('never returns the excluded starter pages', function (): void {
    $results = resolve(DocsSearchService::class)->search('markdown examples table', 10);

    expect(collect($results)->pluck('page')->all())
        ->not->toContain('markdown-examples')
        ->not->toContain('index');
});

it('lists all doc pages next to the results', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(SearchDocs::class, ['query' => 'track a new payment'])
        ->assertOk()
        ->assertStructuredContent(function ($json): void {
            $json->has('all_pages.0.page')
                ->has('all_pages.0.title')
                ->etc();
        });
});

it('finds the page that explains a rare term despite common words in the query', function (): void {
    $results = resolve(DocsSearchService::class)->search('are query strings tracked', 3);

    expect(collect($results)->pluck('page')->all())->toContain('how-to-track-page-views');
});

it('reads a doc page in full', function (): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(SearchDocs::class, ['page' => 'how-to-track-page-views'])
        ->assertOk()
        ->assertSee('Query strings are ignored');
});

it('rejects unknown or path-like page slugs', function (string $page): void {
    SimpleStatsServer::actingAs($this->user)
        ->tool(SearchDocs::class, ['page' => $page])
        ->assertHasErrors();
})->with(['does-not-exist', '../.env', 'index', 'markdown-examples']);
