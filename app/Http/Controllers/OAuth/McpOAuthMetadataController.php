<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Mcp\McpRegistrar;
use Illuminate\Http\JsonResponse;

class McpOAuthMetadataController extends Controller
{
    public function protectedResource(string $path = ''): JsonResponse
    {
        return response()->json(McpRegistrar::protectedResource($path));
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json(McpRegistrar::authorizationServer());
    }
}
