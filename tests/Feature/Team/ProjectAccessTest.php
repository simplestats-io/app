<?php

use App\Actions\CreateUserNotification;
use App\Builder\StatsFilterConditionsBuilder;
use App\Concerns\HasProjectAccessScope;
use App\DTOs\StatsFilterDto;
use App\Enums\StatsRangeType;
use App\Mail\TeamInvitation as TeamInvitationMail;
use App\Mcp\Servers\SimpleStatsServer;
use App\Mcp\Tools\ListStatsCapabilities;
use App\Models\Campaign;
use App\Models\Membership;
use App\Models\Project;
use App\Models\StatsAggregation;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\StatsAggregationsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Finder\SplFileInfo;

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->team->update(['currency' => 'EUR']);
    $this->actingAs($this->owner);

    $this->projectA = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Client A']);
    $this->projectB = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Client B']);

    $this->member = User::factory()->create();
    $this->team->users()->attach($this->member, ['role' => 'viewer', 'project_ids' => [$this->projectA->id]]);
    $this->member->switchTeam($this->team);
});

function projectAccessActAs(User $user): void
{
    auth()->forgetGuards();
    Context::flush();
    test()->actingAs($user->fresh());
}

function projectAccessFilter(int $projectId): StatsFilterDto
{
    return new StatsFilterDto(
        projectId: $projectId,
        rangeAmount: 7,
        rangeType: StatsRangeType::DAY,
        rangeStart: today(),
        comparison: '0',
    );
}

it('limits editors and viewers to their selected projects', function (): void {
    expect($this->member->accessibleProjectIds($this->team))->toBe([$this->projectA->id])
        ->and($this->member->canAccessProject($this->projectA))->toBeTrue()
        ->and($this->member->canAccessProject($this->projectB))->toBeFalse();
});

it('never limits the owner, administrators or members without a selection', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => 'admin', 'project_ids' => [$this->projectA->id]]);

    $editor = User::factory()->create();
    $this->team->users()->attach($editor, ['role' => 'editor']);

    expect($this->owner->accessibleProjectIds($this->team))->toBeNull()
        ->and($admin->accessibleProjectIds($this->team))->toBeNull()
        ->and($editor->accessibleProjectIds($this->team))->toBeNull();
});

it('grants nothing to users outside the team', function (): void {
    expect(User::factory()->create()->accessibleProjectIds($this->team))->toBe([]);
});

it('only lists the accessible projects', function (): void {
    projectAccessActAs($this->member);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('projects.data', 1)
            ->where('projects.data.0.name', 'Client A')
            ->where('permissions.canCreateProject', false));
});

it('gets a 404 for a project outside the selection', function (): void {
    projectAccessActAs($this->member);

    $this->get(route('projects.show', $this->projectB))->assertNotFound();
    $this->get(route('projects.show', $this->projectA))->assertOk();
});

it('only gets per-project views of the accessible projects on the dashboard', function (): void {
    projectAccessActAs($this->member);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('teamStats.projects', 1)
            ->has('teamStats.projects.'.$this->projectA->uuid)
            ->missing('teamStats.projects.0')
            ->where('filters.project_id', $this->projectA->uuid));
});

it('falls back to an accessible project when another project is requested', function (): void {
    projectAccessActAs($this->member);

    $this->get(route('dashboard', ['project_id' => $this->projectB->uuid]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->where('filters.project_id', $this->projectA->uuid));
});

it('cannot create projects as a restricted editor', function (): void {
    Membership::where('user_id', $this->member->id)->update(['role' => 'editor']);
    projectAccessActAs($this->member);

    expect($this->member->fresh()->can('create', Project::class))->toBeFalse();

    Membership::where('user_id', $this->member->id)->update(['project_ids' => null]);

    expect($this->member->fresh()->can('create', Project::class))->toBeTrue();
});

it('gets a 404 for a campaign of a project outside the selection', function (): void {
    projectAccessActAs($this->member);

    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->projectB->id,
    ]);

    $this->get(route('campaigns.show', $campaign))->assertNotFound();
});

it('cannot add ad spend to a campaign of a project outside the selection', function (): void {
    Membership::where('user_id', $this->member->id)->update(['role' => 'editor']);
    projectAccessActAs($this->member);

    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->projectB->id,
    ]);

    $this->post(route('ad-spends.store'), [
        'campaign_id' => $campaign->id,
        'amount' => 10,
        'currency' => 'EUR',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
    ])->assertSessionHasErrors('campaign_id');
});

it('forbids viewers to add ad spend', function (): void {
    projectAccessActAs($this->member);

    $campaign = Campaign::factory()->create([
        'team_id' => $this->team->id,
        'project_id' => $this->projectA->id,
    ]);

    $this->post(route('ad-spends.store'), [
        'campaign_id' => $campaign->uuid,
        'amount' => 10,
        'currency' => 'EUR',
        'starts_on' => '2026-01-01',
        'ends_on' => '2026-01-31',
    ])->assertForbidden();
});

it('only sees itself on the team page', function (): void {
    projectAccessActAs($this->member);

    $this->get(route('teams.show', $this->team))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('team.users', 1)
            ->where('team.users.0.uuid', $this->member->uuid)
            ->has('team.team_invitations', 0)
            ->where('permissions.canViewTeamMembers', false)
            ->has('availableProjects', 0));
});

it('never sees the event usage of the whole team', function (): void {
    $this->team->update(['events_count_monthly' => 1234]);

    $this->get(route('teams.show', $this->team))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('permissions.canViewEventUsage', true)
            ->where('eventsCountMonthly', 1234)
            ->has('projectsEventUsage', 2));

    projectAccessActAs($this->member);

    $this->get(route('teams.show', $this->team))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('permissions.canViewEventUsage', false)
            ->where('eventsCountMonthly', null)
            ->has('projectsEventUsage', 0)
            ->missing('team.events_count_monthly')
            ->has('auth.user.current_team.name')
            ->missing('auth.user.current_team.events_count_monthly')
            ->missing('auth.user.current_team.events_count_overall'));
});

it('only lists the accessible projects to the AI assistant', function (): void {
    projectAccessActAs($this->member);

    Context::add('team_id', $this->team->id);

    SimpleStatsServer::actingAs($this->member->fresh())
        ->tool(ListStatsCapabilities::class)
        ->assertOk()
        ->assertSee('Client A')
        ->assertDontSee('Client B');
});

it('narrows "all" to the accessible projects and empties foreign projects', function (): void {
    projectAccessActAs($this->member);
    Context::add('team_id', $this->team->id);

    $builder = resolve(StatsFilterConditionsBuilder::class);

    expect($builder->build(projectAccessFilter(0))['projectCondition'])->toContain("stats.project_id IN ({$this->projectA->id})")
        ->and($builder->build(projectAccessFilter($this->projectB->id))['projectCondition'])->toContain('AND FALSE')
        ->and($builder->build(projectAccessFilter($this->projectA->id))['projectCondition'])->not->toContain('AND FALSE');
});

it('keeps the team-wide queries of unrestricted members unchanged', function (): void {
    Context::add('team_id', $this->team->id);

    expect(resolve(StatsFilterConditionsBuilder::class)->build(projectAccessFilter(0))['projectCondition'])
        ->not->toContain('IN (')
        ->not->toContain('FALSE');
});

it('never shares or stores the cached "all" aggregate of a restricted member', function (): void {
    Context::add('team_id', $this->team->id);
    $ownerHash = projectAccessFilter(0)->makeHash();

    projectAccessActAs($this->member);
    Context::add('team_id', $this->team->id);

    expect(projectAccessFilter(0)->makeHash())->not->toBe($ownerHash);

    resolve(StatsAggregationsService::class)->storeAggregatedData(
        collect([['date_clear' => now()->format('Y-m-d 00:00:00')]]),
        projectAccessFilter(0),
    );

    expect(StatsAggregation::withoutGlobalScopes()->count())->toBe(0);
});

it('shares the cache entry of an accessible project with administrators', function (): void {
    Context::add('team_id', $this->team->id);
    $ownerHash = projectAccessFilter($this->projectA->id)->makeHash();

    projectAccessActAs($this->member);
    Context::add('team_id', $this->team->id);

    expect(projectAccessFilter($this->projectA->id)->makeHash())->toBe($ownerHash);
});

it('never shares or stores the cache entry of a project outside the selection', function (): void {
    Context::add('team_id', $this->team->id);
    $ownerHash = projectAccessFilter($this->projectB->id)->makeHash();

    projectAccessActAs($this->member);
    Context::add('team_id', $this->team->id);

    expect(projectAccessFilter($this->projectB->id)->makeHash())->not->toBe($ownerHash);

    resolve(StatsAggregationsService::class)->storeAggregatedData(
        collect([['date_clear' => now()->format('Y-m-d 00:00:00')]]),
        projectAccessFilter($this->projectB->id),
    );

    expect(StatsAggregation::withoutGlobalScopes()->count())->toBe(0);
});

it('invites a member limited to selected projects', function (): void {
    Mail::fake();

    $this->post(route('team-members.store', $this->team), [
        'email' => 'client@example.com',
        'role' => 'viewer',
        'project_access' => 'selected',
        'projects' => [$this->projectB->uuid],
    ])->assertSessionHasNoErrors();

    expect(TeamInvitation::where('email', 'client@example.com')->first()->project_ids)->toBe([$this->projectB->id]);
    Mail::assertQueued(TeamInvitationMail::class);
});

it('requires at least one project for a limited member', function (): void {
    Mail::fake();

    $this->post(route('team-members.store', $this->team), [
        'email' => 'client@example.com',
        'role' => 'viewer',
        'project_access' => 'selected',
        'projects' => [],
    ])->assertSessionHasErrorsIn('addTeamMember', 'projects');

    expect(TeamInvitation::count())->toBe(0);
});

it('rejects projects of another team', function (): void {
    $stranger = User::factory()->withPersonalTeam()->create();
    projectAccessActAs($stranger);
    $foreignProject = Project::factory()->create(['team_id' => $stranger->currentTeam->id]);
    projectAccessActAs($this->owner);

    $this->post(route('team-members.store', $this->team), [
        'email' => 'client@example.com',
        'role' => 'viewer',
        'project_access' => 'selected',
        'projects' => [$foreignProject->uuid],
    ])->assertSessionHasErrorsIn('addTeamMember', 'projects.0');
});

it('forbids members who cannot manage the team to change project access', function (): void {
    Mail::fake();
    projectAccessActAs($this->member);

    $this->post(route('team-members.store', $this->team), [
        'email' => 'client@example.com',
        'role' => 'viewer',
        'project_access' => 'selected',
        'projects' => [$this->projectB->uuid],
    ])->assertForbidden();

    $this->put(route('team-members.update', [$this->team, $this->member]), [
        'role' => 'viewer',
        'project_access' => 'all',
    ])->assertForbidden();

    expect(TeamInvitation::count())->toBe(0)
        ->and($this->member->fresh()->accessibleProjectIds($this->team))->toBe([$this->projectA->id]);
});

it('applies the project selection of an accepted invitation', function (): void {
    $invitee = User::factory()->withPersonalTeam()->create(['email' => 'client@example.com']);
    $invitation = $this->team->teamInvitations()->create([
        'email' => 'client@example.com',
        'role' => 'viewer',
        'project_ids' => [$this->projectB->id],
    ]);

    projectAccessActAs($invitee);

    $this->get(URL::signedRoute('team-invitations.accept', ['invitation' => $invitation]))->assertRedirect();

    expect($invitee->fresh()->accessibleProjectIds($this->team))->toBe([$this->projectB->id])
        ->and(TeamInvitation::count())->toBe(0)
        ->and(UserNotification::whereBelongsTo($invitee)->whereMorphedTo('notifiable', $this->projectB)->exists())->toBeTrue()
        ->and(UserNotification::whereBelongsTo($invitee)->whereMorphedTo('notifiable', $this->projectA)->exists())->toBeFalse();
});

it('updates the access of a member and syncs their notifications', function (): void {
    resolve(CreateUserNotification::class)($this->member, $this->projectA);

    $this->put(route('team-members.update', [$this->team, $this->member]), [
        'role' => 'editor',
        'project_access' => 'selected',
        'projects' => [$this->projectB->uuid],
    ])->assertSessionHasNoErrors();

    expect($this->member->fresh()->accessibleProjectIds($this->team))->toBe([$this->projectB->id])
        ->and(UserNotification::whereBelongsTo($this->member)->whereMorphedTo('notifiable', $this->projectA)->exists())->toBeFalse()
        ->and(UserNotification::whereBelongsTo($this->member)->whereMorphedTo('notifiable', $this->projectB)->exists())->toBeTrue();
});

it('gives administrators all projects', function (): void {
    $this->put(route('team-members.update', [$this->team, $this->member]), [
        'role' => 'admin',
        'project_access' => 'selected',
        'projects' => [$this->projectB->uuid],
    ])->assertSessionHasNoErrors();

    expect(Membership::where('user_id', $this->member->id)->first()->project_ids)->toBeNull()
        ->and($this->member->fresh()->accessibleProjectIds($this->team))->toBeNull()
        ->and(UserNotification::whereBelongsTo($this->member)->whereMorphedTo('notifiable', $this->projectA)->exists())->toBeTrue()
        ->and(UserNotification::whereBelongsTo($this->member)->whereMorphedTo('notifiable', $this->projectB)->exists())->toBeTrue();
});

it('shows the project access of members to administrators', function (): void {
    $this->get(route('teams.show', $this->team))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('availableProjects', 2)
            ->where('memberProjects.'.$this->member->uuid, [$this->projectA->uuid])
            ->where('permissions.canViewTeamMembers', true));
});

it('does not create notifications for projects outside the selection', function (): void {
    Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Client C']);

    expect(UserNotification::whereBelongsTo($this->member)->exists())->toBeFalse()
        ->and(UserNotification::whereBelongsTo($this->owner)->count())->toBeGreaterThan(0);
});

it('removes a deleted project from every selection', function (): void {
    $this->team->teamInvitations()->create([
        'email' => 'client@example.com',
        'role' => 'viewer',
        'project_ids' => [$this->projectA->id, $this->projectB->id],
    ]);

    $this->projectA->delete();

    expect(Membership::where('user_id', $this->member->id)->first()->project_ids)->toBe([])
        ->and(TeamInvitation::first()->project_ids)->toBe([$this->projectB->id]);
});

it('forbids triggering the report of another user', function (): void {
    resolve(CreateUserNotification::class)($this->owner, $this->projectA);
    $notification = UserNotification::whereBelongsTo($this->owner)->firstOrFail();

    projectAccessActAs($this->member);

    $this->post(route('notifications.trigger', $notification))->assertForbidden();
});

it('applies the project access scope to every model with a project', function (): void {
    // Cache invalidation must keep reaching the team-wide rows (project_id NULL).
    $exemptModels = [StatsAggregation::class];

    $unscopedModels = collect(File::files(app_path('Models')))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => rescue(fn (): bool => is_subclass_of($class, Model::class), rescue: false, report: false))
        ->reject(fn (string $class): bool => (new ReflectionClass($class))->isAbstract())
        ->filter(fn (string $class): bool => Schema::hasColumn((new $class)->getTable(), 'project_id'))
        ->reject(fn (string $class): bool => in_array(HasProjectAccessScope::class, class_uses_recursive($class), true))
        ->diff($exemptModels)
        ->values()
        ->all();

    expect($unscopedModels)->toBe([]);
});
