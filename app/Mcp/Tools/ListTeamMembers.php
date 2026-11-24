<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ManagesTeamMembers;
use App\Models\Membership;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('List the members of the current team with their role and project access ("all" or the projects they are limited to), plus pending invitations for owners and administrators. Members limited to selected projects only see themselves. Only available when connected as a user (OAuth).')]
#[IsReadOnly]
#[IsIdempotent]
class ListTeamMembers extends Tool
{
    use ManagesTeamMembers;

    public function shouldRegister(): bool
    {
        return $this->signedInUser() !== null;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $user = $this->mcpUser();
            $team = $this->mcpTeam($user);
            $canViewWholeTeam = ! $user->hasRestrictedProjectAccess($team);
            $projectNames = $this->teamProjectNames($team);

            $memberships = Membership::query()
                ->where('team_id', $team->id)
                ->unless($canViewWholeTeam, fn (Builder $query) => $query->where('user_id', $user->id))
                ->get()
                ->keyBy('user_id');

            $members = User::query()
                ->whereKey($memberships->keys())
                ->orderBy('name')
                ->get()
                ->map(function (User $member) use ($memberships, $projectNames): array {
                    $membership = $memberships->get($member->id);

                    return [
                        'user_id' => $member->getRouteKey(),
                        'name' => $member->name,
                        'email' => $member->email,
                        'role' => $membership->role,
                        'projects' => $this->projectAccessPayload($membership->role === 'admin' ? null : $membership->project_ids, $projectNames),
                    ];
                });

            $owner = $team->owner;

            if ($owner instanceof User && $canViewWholeTeam) {
                $members->prepend([
                    'user_id' => $owner->getRouteKey(),
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'role' => 'owner',
                    'projects' => 'all',
                ]);
            }

            return Response::structured([
                'team' => $team->name,
                'members' => $members->values()->all(),
                'pending_invitations' => $user->can('addTeamMember', $team)
                    ? TeamInvitation::query()->whereBelongsTo($team)->orderBy('email')->get()->map(fn (TeamInvitation $invitation): array => [
                        'email' => $invitation->email,
                        'role' => $invitation->role,
                        'projects' => $this->projectAccessPayload($invitation->project_ids, $projectNames),
                    ])->all()
                    : null,
            ]);
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not list the team members. The error was reported, tell the user to try again.');
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
