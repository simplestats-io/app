<?php

namespace App\Mcp\Tools;

use App\Actions\Jetstream\UpdateTeam;
use App\Mcp\Tools\Concerns\ResolvesMcpUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Change the name, currency or timezone of the current team. Omitted fields keep their value. The currency is the one all revenue is reported in, the timezone defines where days start in the dashboards. Only the owner and administrators may do this; confirm the change with the user first.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
class UpdateTeamSettings extends Tool
{
    use ResolvesMcpUser;

    public function shouldRegister(): bool
    {
        return $this->signedInUser() !== null && ! $this->isReadOnlyConnection();
    }

    public function handle(Request $request, UpdateTeam $updateTeam): Response|ResponseFactory
    {
        try {
            $user = $this->mcpUser();
            $this->ensureWriteAccess();
            $team = $this->mcpTeam($user);

            if ($user->cannot('update', $team)) {
                throw new AuthorizationException('Only the team owner and administrators can change the team settings.');
            }

            $team = $updateTeam->update($user, $team, [
                'name' => $request->get('name') ?? $team->name,
                'currency' => strtoupper((string) ($request->get('currency') ?? $team->currency)),
                'timezone' => $request->get('timezone') ?? $team->timezone,
            ]);

            return Response::structured([
                'team' => [
                    'team_id' => $team->getRouteKey(),
                    'name' => $team->name,
                    'currency' => $team->currency,
                    'timezone' => $team->timezone,
                ],
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not update the team. The error was reported, tell the user to try again.');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('New team name, at most 30 characters.'),
            'currency' => $schema->string()
                ->description('ISO-4217 currency code, e.g. EUR or USD.'),
            'timezone' => $schema->string()
                ->description('IANA timezone identifier, e.g. Europe/Berlin.'),
        ];
    }
}
