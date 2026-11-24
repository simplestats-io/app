<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ResolvesMcpUser;
use App\Models\Project;
use App\Services\SubscriptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Get the current team: name, currency, timezone, your role, the plan, and the monthly event usage against the plan limit (null limit = unlimited). Only available when connected as a user (OAuth).')]
#[IsReadOnly]
#[IsIdempotent]
class GetTeam extends Tool
{
    use ResolvesMcpUser;

    public function shouldRegister(): bool
    {
        return $this->signedInUser() !== null;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $user = $this->mcpUser();
            $team = $this->mcpTeam($user);
            $plan = $team->billingOwner?->sparkPlan();
            $limit = function (string $option) use ($plan): ?int {
                $value = $plan?->options[$option] ?? null;

                return $value === SubscriptionService::UNLIMITED_AMOUNT ? null : $value;
            };

            // Like the team page: members limited to selected projects must not see
            // the usage of the other projects.
            $canViewWholeTeam = ! $user->hasRestrictedProjectAccess($team);

            return Response::structured([
                'team' => [
                    'team_id' => $team->getRouteKey(),
                    'name' => $team->name,
                    'currency' => $team->currency,
                    'timezone' => $team->timezone,
                    'your_role' => $user->teamRole($team)?->key,
                    'plan' => $plan?->name,
                    'projects' => $canViewWholeTeam ? ['count' => Project::count(), 'limit' => $limit(Project::class)] : null,
                    'events_this_month' => $canViewWholeTeam ? ['used' => $team->events_count_monthly, 'limit' => $limit('Events')] : null,
                ],
            ]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not load the team. The error was reported, tell the user to try again.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
