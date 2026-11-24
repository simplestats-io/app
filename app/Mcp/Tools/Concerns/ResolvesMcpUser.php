<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\McpScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Over OAuth the MCP server acts as the signed-in user in their current team, with
 * their role and project access. The in-app assistant runs as the session user.
 */
trait ResolvesMcpUser
{
    protected function signedInUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    protected function mcpUser(): User
    {
        return $this->signedInUser()
            ?? throw new AuthorizationException('This tool needs a signed-in user: connect the MCP server via OAuth.');
    }

    protected function mcpTeam(User $user): Team
    {
        $team = $user->currentTeam;

        return $team instanceof Team ? $team : throw new AuthorizationException('Select a team in SimpleStats first.');
    }

    /**
     * Only OAuth connections carry an access token. Users without one (the in-app
     * assistant) are limited by their team role alone.
     */
    protected function isReadOnlyConnection(): bool
    {
        $user = $this->signedInUser();

        return $user?->currentAccessToken() !== null && ! $user->tokenCan(McpScope::WRITE->value);
    }

    protected function ensureWriteAccess(): void
    {
        if ($this->isReadOnlyConnection()) {
            throw new AuthorizationException('This connection is read-only. To make changes, reconnect the MCP server and tick "Also allow changes" on the consent screen.');
        }
    }
}
