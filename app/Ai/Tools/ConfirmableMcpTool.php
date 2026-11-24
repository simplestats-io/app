<?php

namespace App\Ai\Tools;

use App\Models\User;
use App\Services\Ai\AiPendingActionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * Exposes a writing MCP tool to the in-app chat without letting the model execute
 * it: a call only proposes the change, the user confirms it in the chat.
 */
class ConfirmableMcpTool implements Tool
{
    public function __construct(
        protected McpTool $tool,
        protected AiPendingActionService $pendingActions,
        protected User $user,
        protected string $conversationId,
    ) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): string
    {
        return $this->tool->description().' In this chat the call does not change anything yet: it shows the user the exact change with Confirm and Cancel buttons, and only their confirmation executes it.';
    }

    public function handle(Request $request): string
    {
        try {
            return (string) json_encode(
                $this->pendingActions->propose($this->tool, $request->all(), $this->user, $this->conversationId),
                JSON_UNESCAPED_UNICODE,
            );
        } catch (ValidationException $e) {
            return 'The change could not be prepared, nothing was proposed: '.collect($e->errors())->flatten()->implode(' ');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }
}
