<?php

namespace App\Providers;

use App\Enums\McpScope;
use App\Http\Controllers\OAuth\ApproveAuthorizationController;
use App\Http\Controllers\OAuth\RegisterClientController;
use App\Mcp\McpRegistrar;
use App\Services\OAuthKeyService;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController as PassportApproveAuthorizationController;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    public function register(): void
    {
        Passport::$deviceCodeGrantEnabled = false;

        foreach ([AuthorizationServer::class, ResourceServer::class] as $server) {
            $this->app->beforeResolving($server, fn () => resolve(OAuthKeyService::class)->ensureKeys());
        }

        $this->app->singleton(Registrar::class, fn (): McpRegistrar => new McpRegistrar);
        $this->app->bind(OAuthRegisterController::class, RegisterClientController::class);
        $this->app->bind(PassportApproveAuthorizationController::class, ApproveAuthorizationController::class);

        // Passport::authorizationView() binds a singleton that keeps the parameters of
        // the last consent request, which would outlive the request under Octane.
        $this->app->bind(AuthorizationViewResponse::class, fn (): SimpleViewResponse => new SimpleViewResponse(
            fn (array $parameters): SymfonyResponse => $this->consentPage($parameters)->toResponse(request()),
        ));
    }

    public function boot(): void
    {
        Passport::tokensCan(collect(McpScope::cases())->mapWithKeys(fn (McpScope $scope): array => [$scope->value => $scope->title()])->all());
        Passport::defaultScopes([McpScope::READ->value]);
        Passport::tokensExpireIn(CarbonInterval::day());
        Passport::refreshTokensExpireIn(CarbonInterval::days(90));
    }

    /**
     * @param  array{client: Client, scopes: array<int, Scope>, request: Request, authToken: string}  $parameters
     */
    protected function consentPage(array $parameters): Response
    {
        $client = $parameters['client'];
        $scopes = collect($parameters['scopes']);

        return Inertia::render('OAuth/Authorize', [
            'client' => [
                'id' => $client->getKey(),
                'name' => $client->name,
                // A client may register several redirect URIs; the code goes to the one of this request.
                'redirect_host' => parse_url((string) ($parameters['request']->query('redirect_uri') ?: ($client->redirect_uris[0] ?? '')), PHP_URL_HOST) ?: null,
            ],
            'scopes' => $scopes->map(fn (Scope $scope): array => ['id' => $scope->id, 'description' => $scope->description])->values(),
            'requestsWrite' => $scopes->contains(fn (Scope $scope): bool => $scope->id === McpScope::WRITE->value),
            'authToken' => $parameters['authToken'],
            'authorizeUrl' => route('passport.authorizations.approve'),
            'csrfToken' => csrf_token(),
        ]);
    }
}
