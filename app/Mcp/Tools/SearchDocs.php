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

#[Description('Search the SimpleStats documentation (installation, tracking setup, client package configuration, API reference, plans, troubleshooting). Returns the most relevant doc pages with title, link, and an excerpt. Use this for "how do I ..." setup and usage questions, not for stats data.')]
#[IsReadOnly]
class SearchDocs extends Tool
{
    public function __construct(protected DocsSearchService $docsSearchService) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate([
                'query' => ['required', 'string', 'max:200'],
            ]);

            $results = $this->docsSearchService->search($request->get('query'));

            return Response::structured([
                'results' => $results,
                'note' => $results === []
                    ? 'No documentation pages matched. Try different or fewer keywords.'
                    : 'Base your answer on the excerpts and include the url of the most relevant page as a markdown link.',
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
                ->description('Keywords to search the documentation for, e.g. "track payment" or "queue configuration".'),
        ];
    }
}
