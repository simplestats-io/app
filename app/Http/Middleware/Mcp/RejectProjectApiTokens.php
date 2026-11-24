<?php

namespace App\Http\Middleware\Mcp;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Project API tokens (Sanctum, "id|secret") used to be accepted on /mcp. Passport
 * access tokens are JWTs and never contain a pipe, so such a token gets an explicit
 * hint instead of the generic 401. The 401 keeps the WWW-Authenticate challenge,
 * which lets MCP clients start the OAuth flow right away.
 */
class RejectProjectApiTokens
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Str::contains((string) $request->bearerToken(), '|')) {
            return response()->json([
                'message' => 'The MCP server requires an OAuth connection, project API tokens are not accepted. Remove the Authorization header from your MCP client config and reconnect, your client then signs you in to '.config('app.name').'. See '.url('/docs/mcp-server.html').'.',
            ], 401);
        }

        return $next($request);
    }
}
