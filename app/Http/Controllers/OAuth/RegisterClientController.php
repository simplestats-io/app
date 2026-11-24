<?php

namespace App\Http\Controllers\OAuth;

use App\Enums\McpScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * Dynamic client registration of laravel/mcp answers with its single mcp:use scope,
 * which some clients then keep requesting. Report every MCP scope instead.
 */
class RegisterClientController extends OAuthRegisterController
{
    public function __invoke(Request $request): JsonResponse
    {
        $response = parent::__invoke($request);

        if ($response->getStatusCode() === 201) {
            $response->setData([...$response->getData(true), 'scope' => implode(' ', McpScope::values())]);
        }

        return $response;
    }
}
