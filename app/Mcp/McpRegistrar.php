<?php

namespace App\Mcp;

use App\Enums\McpScope;
use App\Http\Controllers\OAuth\McpOAuthMetadataController;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Mcp\Server\Registrar;

/**
 * laravel/mcp advertises its single mcp:use scope. MCP clients request the scopes
 * from this metadata, so the write scope must be listed here to be grantable.
 *
 * The vendor registers the discovery routes as closures calling static::, which
 * php artisan route:cache serializes bound to the vendor class: in production the
 * override was silently lost. Controller actions survive the route cache.
 */
class McpRegistrar extends Registrar
{
    public function oauthRoutes(string $oauthPrefix = 'oauth', bool $registration = true): void
    {
        static::ensureMcpScope();

        if (! $this->hasGetRoute('.well-known/oauth-protected-resource')) {
            Route::get('/.well-known/oauth-protected-resource', [McpOAuthMetadataController::class, 'protectedResource'])
                ->name('mcp.oauth.protected-resource');
        }

        if (! $this->hasGetRoute('.well-known/oauth-authorization-server')) {
            Route::get('/.well-known/oauth-authorization-server', [McpOAuthMetadataController::class, 'authorizationServer'])
                ->name('mcp.oauth.authorization-server');
        }

        Route::get('/.well-known/oauth-protected-resource/{path}', [McpOAuthMetadataController::class, 'protectedResource'])
            ->where('path', '.*')
            ->name('mcp.oauth.protected-resource.nested');

        Route::get('/.well-known/oauth-authorization-server/{path}', [McpOAuthMetadataController::class, 'authorizationServer'])
            ->where('path', '.*')
            ->name('mcp.oauth.authorization-server.nested');

        if ($registration) {
            Route::post($oauthPrefix.'/register', OAuthRegisterController::class)
                ->name('mcp.oauth.register');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function authorizationServer(): array
    {
        return [...static::authorizationServerMetadata(''), 'scopes_supported' => McpScope::values()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function protectedResource(string $path): array
    {
        return [...static::protectedResourceMetadata($path), 'scopes_supported' => McpScope::values()];
    }
}
