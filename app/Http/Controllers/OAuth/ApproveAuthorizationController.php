<?php

namespace App\Http\Controllers\OAuth;

use App\Enums\McpScope;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

/**
 * Clients register themselves and pick their own name, so write access is an
 * explicit opt-in on the consent screen. Passport has no partial approval, so
 * the write scope is dropped from the pending authorization request unless the
 * user ticked it.
 */
class ApproveAuthorizationController extends PassportApproveAuthorizationController
{
    protected function getAuthRequestFromSession(Request $request): AuthorizationRequestInterface
    {
        $authRequest = parent::getAuthRequestFromSession($request);

        if (! $request->boolean('allow_write')) {
            $authRequest->setScopes(collect($authRequest->getScopes())
                ->reject(fn (ScopeEntityInterface $scope): bool => $scope->getIdentifier() === McpScope::WRITE->value)
                ->push(new Scope(McpScope::READ->value))
                ->unique(fn (ScopeEntityInterface $scope): string => $scope->getIdentifier())
                ->values()
                ->all());
        }

        return $authRequest;
    }
}
