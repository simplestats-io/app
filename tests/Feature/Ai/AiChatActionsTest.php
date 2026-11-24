<?php

use App\Ai\Agents\StatsAssistant;
use App\Ai\Tools\ConfirmableMcpTool;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\Ai\AiChatStreamer;
use App\Services\Ai\AiPendingActionService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Tools\Request as ToolRequest;

beforeEach(function (): void {
    $this->owner = User::factory()->withPersonalTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->team->update(['currency' => 'EUR']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->otherProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog']);

    $this->conversationId = resolve(ConversationStore::class)->storeConversation($this->owner->id, 'Chat');
});

function chatMember(Team $team, string $role, ?array $projectIds = null): User
{
    $member = User::factory()->create();
    $team->users()->attach($member, ['role' => $role, 'project_ids' => $projectIds]);
    $member->switchTeam($team);

    return $member->fresh();
}

/**
 * @return array<string, ConfirmableMcpTool|object>
 */
function chatTools(User $user, ?string $conversationId): array
{
    auth()->setUser($user);
    Context::add('team_id', $user->current_team_id);

    $agent = new StatsAssistant;

    if ($conversationId !== null) {
        $agent->continue($conversationId, as: $user);
    }

    return collect($agent->tools())->keyBy(fn (object $tool): string => $tool->name())->all();
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{result: string, action: array<string, mixed>|null}
 */
function proposeInChat(User $user, string $conversationId, string $tool, array $arguments): array
{
    $result = chatTools($user, $conversationId)[$tool]->handle(new ToolRequest($arguments));

    return ['result' => $result, 'action' => resolve(AiPendingActionService::class)->pullProposed()[0] ?? null];
}

function confirmInChat(User $user, string $actionId, string $conversationId)
{
    auth()->forgetGuards();

    return test()->actingAs($user)->postJson(route('ai.pending-actions.confirm', $actionId), ['conversation_id' => $conversationId]);
}

it('only proposes a write from the model and writes nothing before the user confirms', function (): void {
    StatsAssistant::fake([
        new ToolCall('call_1', 'create-campaign', ['name' => 'Black Week 2026', 'project_id' => $this->project->id, 'utm_campaigns' => ['black-week-2026']]),
        'Please confirm the card.',
    ]);

    auth()->setUser($this->owner);
    Context::add('team_id', $this->team->id);

    (new StatsAssistant)->continue($this->conversationId, as: $this->owner)->prompt('Create a Black Week campaign for the shop');

    $action = resolve(AiPendingActionService::class)->pullProposed()[0];

    expect(Campaign::withoutGlobalScopes()->count())->toBe(0)
        ->and($action['title'])->toBe('Create campaign')
        ->and($action['details'])->toBe([
            ['label' => 'Name', 'value' => 'Black Week 2026'],
            ['label' => 'Project', 'value' => 'Shop'],
            ['label' => 'Link utm_campaign values', 'value' => 'black-week-2026'],
        ]);
});

it('streams proposed actions to the frontend', function (): void {
    chatTools($this->owner, $this->conversationId)['create-campaign']->handle(new ToolRequest(['name' => 'Autumn Sale']));

    $response = resolve(AiChatStreamer::class)->response(['{"type":"tool_result"}'], $this->conversationId);

    // Laravel's generator stream wrapper calls ob_flush(), which pushes the
    // echoed content one buffer level up: capture with two nested buffers.
    ob_start();
    ob_start();
    $response->sendContent();
    $output = ob_get_clean().ob_get_clean();

    expect($output)
        ->toContain('"type":"pending_action"')
        ->toContain('Autumn Sale')
        ->toContain('All projects');
});

it('executes a confirmed action exactly once and records the outcome in the conversation', function (): void {
    ['result' => $result, 'action' => $action] = proposeInChat($this->owner, $this->conversationId, 'create-campaign', [
        'name' => 'Black Week 2026',
        'project_id' => $this->project->id,
    ]);

    expect($result)->toContain('awaiting_user_confirmation');

    confirmInChat($this->owner, $action['id'], $this->conversationId)
        ->assertOk()
        ->assertJsonPath('status', 'done');

    expect(Campaign::withoutGlobalScopes()->where('name', 'Black Week 2026')->sole()->project_id)->toBe($this->project->id)
        ->and(ConversationMessage::query()->where('conversation_id', $this->conversationId)->sole())
        ->role->toBe('assistant')
        ->content->toContain('Confirmed and done')->toContain('Black Week 2026');

    confirmInChat($this->owner, $action['id'], $this->conversationId)->assertNotFound();

    expect(Campaign::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses confirmations by other users, other teams and other conversations', function (): void {
    ['action' => $action] = proposeInChat($this->owner, $this->conversationId, 'create-campaign', ['name' => 'Spring Sale']);

    $stranger = User::factory()->withPersonalTeam()->create();
    $teamAdmin = chatMember($this->team, 'admin');
    $otherConversationId = resolve(ConversationStore::class)->storeConversation($this->owner->id, 'Other chat');

    confirmInChat($stranger, $action['id'], $this->conversationId)->assertNotFound();
    confirmInChat($teamAdmin, $action['id'], $this->conversationId)->assertNotFound();
    confirmInChat($this->owner, $action['id'], $otherConversationId)->assertNotFound();

    $secondTeam = Team::factory()->create(['user_id' => $this->owner->id, 'personal_team' => false]);
    $this->owner->switchTeam($secondTeam);
    confirmInChat($this->owner->fresh(), $action['id'], $this->conversationId)->assertNotFound();

    expect(Campaign::withoutGlobalScopes()->count())->toBe(0);

    // Failed attempts do not use the action up for its owner.
    $this->owner->switchTeam($this->team);
    confirmInChat($this->owner->fresh(), $action['id'], $this->conversationId)->assertOk()->assertJsonPath('status', 'done');
});

it('offers viewers no writing tools and re-checks the role at execution time', function (): void {
    $viewer = chatMember($this->team, 'viewer');

    expect(chatTools($viewer, $this->conversationId))
        ->toHaveKey('list-campaigns')
        ->not->toHaveKey('create-campaign')
        ->not->toHaveKey('create-project');

    $editor = chatMember($this->team, 'editor');
    $conversationId = resolve(ConversationStore::class)->storeConversation($editor->id, 'Chat');
    ['action' => $action] = proposeInChat($editor, $conversationId, 'create-campaign', ['name' => 'Spring Sale', 'project_id' => $this->project->id]);

    $this->team->users()->updateExistingPivot($editor, ['role' => 'viewer']);

    confirmInChat($editor->fresh(), $action['id'], $conversationId)
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'does not allow creating campaigns'));

    expect(Campaign::withoutGlobalScopes()->count())->toBe(0);
});

it('does not let a limited member touch a project outside their selection', function (): void {
    $member = chatMember($this->team, 'editor', [$this->project->id]);
    $conversationId = resolve(ConversationStore::class)->storeConversation($member->id, 'Chat');

    ['result' => $result, 'action' => $action] = proposeInChat($member, $conversationId, 'update-project', [
        'project_id' => $this->otherProject->id,
        'name' => 'Hijacked',
    ]);

    expect($result)->toContain('nothing was proposed')
        ->and($action)->toBeNull();

    ['action' => $action] = proposeInChat($member, $conversationId, 'update-project', ['project_id' => $this->project->id, 'name' => 'Shop 2']);

    $this->team->users()->updateExistingPivot($member, ['project_ids' => [$this->otherProject->id]]);

    confirmInChat($member->fresh(), $action['id'], $conversationId)
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($this->project->fresh()->name)->toBe('Shop');
});

it('discards a cancelled action', function (): void {
    ['action' => $action] = proposeInChat($this->owner, $this->conversationId, 'create-campaign', ['name' => 'Spring Sale']);

    auth()->forgetGuards();

    $this->actingAs($this->owner)
        ->deleteJson(route('ai.pending-actions.destroy', $action['id']), ['conversation_id' => $this->conversationId])
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    confirmInChat($this->owner, $action['id'], $this->conversationId)->assertNotFound();

    expect(Campaign::withoutGlobalScopes()->count())->toBe(0)
        ->and(ConversationMessage::query()->where('conversation_id', $this->conversationId)->sole()->content)->toContain('Cancelled');
});

it('restores open actions when the conversation is loaded again', function (): void {
    ['action' => $action] = proposeInChat($this->owner, $this->conversationId, 'create-campaign', ['name' => 'Spring Sale']);
    $stranger = User::factory()->withPersonalTeam()->create();

    auth()->forgetGuards();

    $this->actingAs($this->owner)
        ->getJson(route('ai.conversations.show', $this->conversationId))
        ->assertOk()
        ->assertJsonPath('pending_actions.0.id', $action['id'])
        ->assertJsonPath('pending_actions.0.title', 'Create campaign');

    confirmInChat($this->owner, $action['id'], $this->conversationId)->assertOk();

    $this->actingAs($this->owner)
        ->getJson(route('ai.conversations.show', $this->conversationId))
        ->assertJsonCount(0, 'pending_actions');

    $this->actingAs($stranger)->getJson(route('ai.conversations.show', $this->conversationId))->assertForbidden();
});

it('keeps only the proposals of the latest turn confirmable', function (): void {
    ['action' => $broad] = proposeInChat($this->owner, $this->conversationId, 'create-campaign', ['name' => 'Spring Sale']);
    ['action' => $narrow] = proposeInChat($this->owner, $this->conversationId, 'create-campaign', ['name' => 'Spring Sale', 'project_id' => $this->project->id]);

    auth()->forgetGuards();

    $this->actingAs($this->owner)
        ->getJson(route('ai.conversations.show', $this->conversationId))
        ->assertJsonCount(1, 'pending_actions')
        ->assertJsonPath('pending_actions.0.id', $narrow['id']);

    confirmInChat($this->owner, $broad['id'], $this->conversationId)->assertNotFound();
    confirmInChat($this->owner, $narrow['id'], $this->conversationId)->assertOk();

    expect(Campaign::withoutGlobalScopes()->sole()->project_id)->toBe($this->project->id);
});

it('hands the token of a new project to the user only, never to the conversation', function (): void {
    ['action' => $action] = proposeInChat($this->owner, $this->conversationId, 'create-project', [
        'name' => 'Docs',
        'domain' => 'docs.example.com',
        'usages' => ['visitors', 'users'],
    ]);

    expect($action['details'])->toContain(['label' => 'Tracks', 'value' => Project::getUsages()->only(['visitors', 'users'])->pluck('name')->implode(', ')]);

    $response = confirmInChat($this->owner, $action['id'], $this->conversationId)
        ->assertOk()
        ->assertJsonPath('status', 'done');

    $token = $response->json('api_token');

    expect($token)->toBeString()->not->toBeEmpty()
        ->and(Project::withoutGlobalScopes()->where('name', 'Docs')->exists())->toBeTrue()
        ->and(ConversationMessage::query()->where('conversation_id', $this->conversationId)->sole()->content)->not->toContain($token);
});

it('shows exactly whom an invitation goes to before it is sent', function (): void {
    Mail::fake();

    ['action' => $action] = proposeInChat($this->owner, $this->conversationId, 'invite-team-member', [
        'email' => 'new@example.com',
        'role' => 'viewer',
        'project_ids' => [$this->project->id],
    ]);

    expect($action['details'])->toBe([
        ['label' => 'Email', 'value' => 'new@example.com'],
        ['label' => 'Role', 'value' => 'viewer'],
        ['label' => 'Project access', 'value' => 'Shop'],
    ]);

    Mail::assertNothingQueued();
    Mail::assertNothingSent();

    confirmInChat($this->owner, $action['id'], $this->conversationId)->assertOk()->assertJsonPath('status', 'done');

    expect($this->team->teamInvitations()->where('email', 'new@example.com')->exists())->toBeTrue();
});

it('states on the card when a proposal applies to all projects', function (string $tool, array $arguments, string $label): void {
    ['action' => $action] = proposeInChat($this->owner, $this->conversationId, $tool, $arguments);

    expect($action['details'])->toContain(['label' => $label, 'value' => 'All projects']);
})->with([
    'invitation without project_ids' => ['invite-team-member', ['email' => 'new@example.com', 'role' => 'viewer'], 'Project access'],
    'invitation with null project_ids' => ['invite-team-member', ['email' => 'new@example.com', 'role' => 'viewer', 'project_ids' => null], 'Project access'],
    'campaign with null project_id' => ['create-campaign', ['name' => 'Black Week', 'project_id' => null], 'Project'],
]);

it('names the team whose settings change', function (): void {
    ['action' => $action] = proposeInChat($this->owner, $this->conversationId, 'update-team-settings', ['currency' => 'USD']);

    expect($action['details'])->toBe([
        ['label' => 'Team', 'value' => $this->team->name],
        ['label' => 'New currency', 'value' => 'USD'],
    ])->and($this->team->fresh()->currency)->toBe('EUR');
});
