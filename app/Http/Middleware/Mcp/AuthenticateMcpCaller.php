<?php

namespace App\Http\Middleware\Mcp;

use App\Enums\McpScope;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP endpoint only accepts OAuth access tokens (Passport), which act as the
 * user in their current team with their role and project access. Passport tokens
 * without the MCP scope (e.g. personal access tokens) are refused.
 */
class AuthenticateMcpCaller
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $caller = auth('api')->user();

        if (! $caller instanceof User || ($caller->tokenCant(McpScope::READ->value) && $caller->tokenCant(McpScope::WRITE->value))) {
            return $this->deny('The MCP endpoint requires an OAuth connection.');
        }

        if (! $caller->hasVerifiedEmail()) {
            return $this->deny('Verify your email address in '.config('app.name').' before connecting an AI client.');
        }

        if (! $caller->currentTeam || ! $caller->belongsToTeam($caller->currentTeam)) {
            return $this->deny('Select a team in '.config('app.name').' before connecting an AI client.');
        }

        return $next($request);
    }

    protected function deny(string $message): Response
    {
        return response()->json(['message' => $message], 403);
    }
}
