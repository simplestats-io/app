<?php

namespace App\Mcp;

use App\Enums\McpScope;
use Laravel\Mcp\Server\Registrar;

/**
 * laravel/mcp advertises its single mcp:use scope. MCP clients request the scopes
 * from this metadata, so the write scope must be listed here to be grantable.
 */
class McpRegistrar extends Registrar
{
    protected static function authorizationServerMetadata(string $oauthPrefix): array
    {
        return [...parent::authorizationServerMetadata($oauthPrefix), 'scopes_supported' => McpScope::values()];
    }

    protected static function protectedResourceMetadata(string $path): array
    {
        return [...parent::protectedResourceMetadata($path), 'scopes_supported' => McpScope::values()];
    }
}
