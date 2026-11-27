<?php

namespace App\Mcp\Tools;

use App\Services\Ai\DocsSearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Search the SimpleStats documentation (installation, tracking setup, what is and is not tracked, client package configuration, API reference, plans, troubleshooting). Returns the most relevant doc pages with title, link and excerpts, plus a list of all doc pages. Pass `page` instead to read one page in full. Use this for "how do I ..." and "can SimpleStats ..." questions about the product, not for stats data. If the excerpts do not answer the question, read the most promising page or search again with other keywords before saying the docs do not cover it.')]
#[IsReadOnly]
class SearchDocs extends Tool
{
    public function __construct(protected DocsSearchService $docsSearchService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate([
                'query' => ['required_without:page', 'nullable', 'string', 'max:200'],
                'page' => ['nullable', 'string', 'max:100'],
            ]);

            if ($request->filled('page')) {
                $page = $this->docsSearchService->page($request->get('page'));

                if ($page === null) {
                    return Response::error('Unknown doc page. Use one of the page slugs returned by a search.');
                }

                return Response::structured([
                    ...$page,
                    'note' => 'Base your answer on this page and include its url as a markdown link.',
                ]);
            }

            $results = $this->docsSearchService->search($request->get('query'));

            return Response::structured([
                'results' => $results,
                'all_pages' => $this->docsSearchService->pages(),
                'note' => $results === []
                    ? 'No documentation pages matched. Try different or fewer keywords, or read a page from all_pages whose title fits.'
                    : 'Base your answer on the excerpts and include the url of the most relevant page as a markdown link. If they do not answer the question, read a page in full via `page`.',
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not search the docs. The error was reported, tell the user to try again or rephrase.');
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Keywords to search the documentation for, e.g. "track payment" or "query string". Required unless `page` is given.'),
            'page' => $schema->string()
                ->description('Slug of a doc page to read in full (the `page` value of a search result or of all_pages), e.g. "how-to-track-page-views".'),
        ];
    }
}
