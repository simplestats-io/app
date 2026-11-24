<?php

namespace App\Mcp\Tools;

use App\Services\Ai\AppPageCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('List the app pages the user can navigate to, each with a short description of what can be done there and its URL. Use this when the user asks WHERE to find or do something in the app ("Where can I create a project?", "How do I invite a team member?"), then answer with the page name and its link.')]
#[IsReadOnly]
class ListAppPages extends Tool
{
    public function __construct(protected AppPageCatalog $appPageCatalog) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured([
                'pages' => $this->appPageCatalog->pages(),
                'note' => 'Answer with the matching page title and include its url as a markdown link. Only link pages from this list, never invent URLs. URLs may contain a #anchor that scrolls to the right section.',
            ]);
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not list app pages. The error was reported, tell the user to try again or rephrase.');
        }
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
