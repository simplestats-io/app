<?php

namespace App\Services\Ai;

use App\Ai\Agents\StatsAssistant;
use App\Helpers\AppHelper;
use App\Mcp\Tools\CreateCampaign;
use App\Mcp\Tools\CreateProject;
use App\Mcp\Tools\InviteTeamMember;
use App\Mcp\Tools\RecordAdSpend;
use App\Mcp\Tools\UpdateAdSpend;
use App\Mcp\Tools\UpdateCampaign;
use App\Mcp\Tools\UpdateProject;
use App\Mcp\Tools\UpdateTeamSettings;
use App\Models\AdSpend;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Models\Conversation;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as McpTool;

/**
 * The chat reads attacker-controllable data (referrers, utm values, page paths,
 * custom properties), so the model never executes a writing tool itself: its call
 * only stores a pending action, and the real tool runs once the user confirms the
 * card in the chat. The tool then re-checks role, project access and plan limits
 * at execution time, exactly like over MCP.
 */
class AiPendingActionService
{
    /**
     * @var list<class-string<McpTool>>
     */
    public const array WRITE_TOOLS = [
        CreateCampaign::class,
        UpdateCampaign::class,
        RecordAdSpend::class,
        UpdateAdSpend::class,
        CreateProject::class,
        UpdateProject::class,
        UpdateTeamSettings::class,
        InviteTeamMember::class,
    ];

    private const string CACHE_PREFIX = 'ai-pending-action:';

    private const int TTL_SECONDS = 15 * 60;

    private const string ALL_PROJECTS = 'all';

    private const array TITLES = [
        'create-campaign' => 'Create campaign',
        'update-campaign' => 'Update campaign',
        'record-ad-spend' => 'Record ad spend',
        'update-ad-spend' => 'Update ad spend',
        'create-project' => 'Create project',
        'update-project' => 'Update project',
        'update-team-settings' => 'Change team settings',
        'invite-team-member' => 'Invite team member',
    ];

    private const array LABELS = [
        'campaign_id' => 'Campaign',
        'ad_spend_id' => 'Ad spend entry',
        'project_id' => 'Project',
        'name' => 'Name',
        'domain' => 'Domain',
        'usages' => 'Tracks',
        'flags' => 'Properties',
        'utm_campaigns' => 'Link utm_campaign values',
        'link_utm_campaigns' => 'Link utm_campaign values',
        'amount' => 'Amount',
        'currency' => 'Currency',
        'starts_on' => 'From',
        'ends_on' => 'To',
        'timezone' => 'Timezone',
        'email' => 'Email',
        'role' => 'Role',
        'project_ids' => 'Project access',
        'team' => 'Team',
    ];

    /**
     * Proposals of the running request, drained into the SSE stream.
     *
     * @var list<array<string, mixed>>
     */
    private array $proposed = [];

    /**
     * Viewers only read, so the writing tools are not offered to them at all. The
     * finer checks (admin-only tools, project access, plan limits) stay in the tools.
     */
    public function canProposeWrites(User $user): bool
    {
        $team = $user->currentTeam;

        return $team instanceof Team
            && ($user->hasTeamPermission($team, 'action:create') || $user->hasTeamPermission($team, 'action:update'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function propose(McpTool $tool, array $arguments, User $user, string $conversationId): array
    {
        $arguments = Arr::only($arguments, array_keys($tool->schema(new JsonSchemaTypeFactory)));
        $action = [
            'id' => (string) Str::uuid(),
            'title' => self::TITLES[$tool->name()] ?? Str::headline($tool->name()),
            'details' => $this->details($tool->name(), $arguments),
            'proposed_at' => now()->toIso8601String(),
        ];
        $indexKey = self::CACHE_PREFIX.'conversation:'.$conversationId;

        // A new turn supersedes what earlier turns proposed: a follow-up like "only
        // for this project" must not leave the broader first proposal confirmable.
        // Several proposals within one turn stay open together.
        if ($this->proposed === []) {
            collect(Arr::wrap(Cache::pull($indexKey)))->each(fn (string $id): bool => Cache::forget(self::CACHE_PREFIX.$id));
        }

        Cache::put(self::CACHE_PREFIX.$action['id'], [
            ...$action,
            'tool' => $tool::class,
            'arguments' => $arguments,
            'user_id' => $user->id,
            'team_id' => AppHelper::getTeamId(),
            'conversation_id' => $conversationId,
        ], self::TTL_SECONDS);

        Cache::put($indexKey, [...Cache::get($indexKey, []), $action['id']], self::TTL_SECONDS);

        $this->proposed[] = $action;

        return [
            'status' => 'awaiting_user_confirmation',
            'action' => Arr::except($action, 'id'),
            'note' => 'Nothing has been changed yet. The user now sees this action as a card with Confirm and Cancel buttons in the chat. Ask them to review and confirm it there, and never say it is done: you will see the outcome in the conversation once they decide.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pullProposed(): array
    {
        return tap($this->proposed, fn (): array => $this->proposed = []);
    }

    /**
     * The cards only live in the panel, so a reload or a fresh panel asks for the
     * still open actions of the conversation again.
     *
     * @return list<array{id: string, title: string, details: list<array{label: string, value: string}>, proposed_at: string}>
     */
    public function openFor(string $conversationId, User $user): array
    {
        return collect(Arr::wrap(Cache::get(self::CACHE_PREFIX.'conversation:'.$conversationId)))
            ->map(fn (string $id): mixed => Cache::get(self::CACHE_PREFIX.$id))
            ->filter(fn (mixed $pending): bool => is_array($pending)
                && $pending['user_id'] === $user->id
                && $pending['team_id'] === AppHelper::getTeamId()
                && $pending['conversation_id'] === $conversationId)
            ->map(fn (array $pending): array => Arr::only($pending, ['id', 'title', 'details', 'proposed_at']))
            ->values()
            ->all();
    }

    /**
     * @return array{status: string, message: string, api_token?: string}
     */
    public function confirm(string $actionId, string $conversationId, User $user): array
    {
        $pending = $this->claim($actionId, $conversationId, $user);
        $response = $this->execute($pending['tool'], $pending['arguments']);
        $structured = $response instanceof ResponseFactory ? $response->getStructuredContent() : null;

        if ($structured === null) {
            $error = $response instanceof McpResponse ? (string) $response->content() : 'The change could not be completed.';
            $this->appendOutcome($conversationId, $user, "**Could not complete:** {$pending['title']}. {$error}");

            return ['status' => 'failed', 'message' => $error];
        }

        $this->appendOutcome($conversationId, $user, "**Confirmed and done:** {$pending['title']}\n".$this->detailList($pending['details']));

        // The token of a new project is shown in the card only: it must neither be
        // stored in the conversation nor reach the AI provider.
        return array_filter([
            'status' => 'done',
            'message' => 'Done.',
            'api_token' => $structured['api_token'] ?? null,
        ]);
    }

    public function cancel(string $actionId, string $conversationId, User $user): void
    {
        $pending = $this->claim($actionId, $conversationId, $user);

        $this->appendOutcome($conversationId, $user, "**Cancelled:** {$pending['title']}. Nothing was changed.");
    }

    /**
     * Unknown, expired, foreign and already handled actions all answer 404, so an
     * action id reveals nothing to anyone but its owner.
     *
     * @return array<string, mixed>
     */
    private function claim(string $actionId, string $conversationId, User $user): array
    {
        $pending = Cache::get(self::CACHE_PREFIX.$actionId);

        abort_unless(
            is_array($pending)
                && $pending['user_id'] === $user->id
                && $pending['team_id'] === AppHelper::getTeamId()
                && $pending['conversation_id'] === $conversationId
                && in_array($pending['tool'], self::WRITE_TOOLS, true),
            404,
            'This action expired or was already handled. Ask the assistant again.',
        );

        // add() is atomic, so two concurrent confirms cannot both pass.
        abort_unless(Cache::add(self::CACHE_PREFIX.$actionId.':claimed', true, self::TTL_SECONDS), 404, 'This action was already handled.');
        Cache::forget(self::CACHE_PREFIX.$actionId);

        return $pending;
    }

    /**
     * @param  class-string<McpTool>  $toolClass
     * @param  array<string, mixed>  $arguments
     */
    private function execute(string $toolClass, array $arguments): mixed
    {
        $container = app();
        $container->instance(McpRequest::class, new McpRequest($arguments));

        try {
            return $container->call([resolve($toolClass), 'handle']);
        } finally {
            $container->forgetInstance(McpRequest::class);
        }
    }

    /**
     * The outcome lands in the conversation as an assistant message, so the model
     * knows on the next turn whether the change happened. Impersonated chats are
     * not persisted (NullConversationStore), their conversation does not exist.
     */
    private function appendOutcome(string $conversationId, User $user, string $content): void
    {
        $conversation = Conversation::query()->whereKey($conversationId)->where('user_id', $user->id)->first();

        $conversation?->messages()->create([
            'id' => (string) Str::uuid7(),
            'user_id' => $user->id,
            'agent' => StatsAssistant::class,
            'role' => 'assistant',
            'content' => $content,
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'usage' => [],
            'meta' => [],
        ]);
        $conversation?->touch();
    }

    /**
     * @param  list<array{label: string, value: string}>  $details
     */
    private function detailList(array $details): string
    {
        return collect($details)->map(fn (array $detail): string => "- {$detail['label']}: ".Str::squish($detail['value']))->implode("\n");
    }

    /**
     * A human readable list of every argument the tool will run with. Referenced
     * ids resolve through the team and project access scopes, so ids of other
     * teams or of projects outside a limited member's selection are refused here
     * already.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array{label: string, value: string}>
     *
     * @throws ValidationException
     */
    private function details(string $toolName, array $arguments): array
    {
        if ($toolName === 'record-ad-spend' && blank($arguments['currency'] ?? null)) {
            $arguments['currency'] = $this->campaign($arguments['campaign_id'] ?? null)->team->currency;
        }

        // A missing or null scope runs unrestricted, so the card has to say so instead of dropping the row.
        if ($toolName === 'create-campaign') {
            $arguments = array_key_exists('project_id', $arguments) ? $arguments : ['project_id' => 0, ...$arguments];
            $arguments['project_id'] ??= 0;
        }

        if ($toolName === 'invite-team-member') {
            $arguments['project_ids'] ??= self::ALL_PROJECTS;
        }

        if ($toolName === 'update-team-settings') {
            $arguments = ['team' => Team::query()->whereKey(AppHelper::getTeamId())->value('name'), ...$arguments];
        }

        $isUpdate = Str::startsWith($toolName, 'update-');

        return collect($arguments)
            ->reject(fn (mixed $value): bool => $value === null)
            ->map(fn (mixed $value, string $key): array => [
                'label' => $isUpdate && in_array($key, ['name', 'domain', 'usages', 'flags', 'amount', 'currency', 'starts_on', 'ends_on', 'timezone'], true)
                    ? 'New '.Str::lower(self::LABELS[$key])
                    : self::LABELS[$key] ?? Str::headline($key),
                'value' => $this->displayValue($key, $value),
            ])
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function displayValue(string $key, mixed $value): string
    {
        return match ($key) {
            'project_id' => (int) $value === 0 ? 'All projects' : $this->project($value)->name,
            'project_ids' => $value === self::ALL_PROJECTS ? 'All projects' : $this->projectNames((array) $value),
            'campaign_id' => $this->campaign($value)->name,
            'ad_spend_id' => $this->adSpendLabel($value),
            'usages' => $this->definitionNames(Project::getUsages()->all(), (array) $value),
            'flags' => $this->definitionNames(Project::getFlags()->all(), (array) $value),
            default => is_array($value) ? (collect($value)->map(fn (mixed $item): string => (string) $item)->implode(', ') ?: 'None') : (string) $value,
        };
    }

    /**
     * @throws ValidationException
     */
    private function project(mixed $projectId): Project
    {
        return (is_numeric($projectId) ? Project::query()->whereKey((int) $projectId)->first() : null)
            ?? throw ValidationException::withMessages(['project_id' => 'No accessible project with this project_id exists. Use list-projects to look it up.']);
    }

    /**
     * @param  array<int, mixed>  $projectIds
     *
     * @throws ValidationException
     */
    private function projectNames(array $projectIds): string
    {
        $ids = collect($projectIds)->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique();
        $names = Project::query()->whereKey($ids->all())->pluck('name');

        if ($ids->isEmpty() || $names->count() !== count($projectIds)) {
            throw ValidationException::withMessages(['project_ids' => 'One of the selected projects does not exist or is not accessible. Use list-projects to look them up.']);
        }

        return $names->implode(', ');
    }

    /**
     * @throws ValidationException
     */
    private function campaign(mixed $uuid): Campaign
    {
        return (is_string($uuid) && Str::isUuid($uuid) ? Campaign::query()->where('uuid', $uuid)->first() : null)
            ?? throw ValidationException::withMessages(['campaign_id' => 'No campaign with this campaign_id exists in the accessible projects. Use list-campaigns to look it up.']);
    }

    /**
     * @throws ValidationException
     */
    private function adSpendLabel(mixed $uuid): string
    {
        $adSpend = (is_string($uuid) && Str::isUuid($uuid) ? AdSpend::query()->where('uuid', $uuid)->whereHas('campaign')->first() : null)
            ?? throw ValidationException::withMessages(['ad_spend_id' => 'No ad spend entry with this ad_spend_id exists in the accessible projects. Use list-campaigns to look it up.']);

        return sprintf(
            '%s: %s %s, %s to %s',
            $adSpend->campaign->name,
            number_format($adSpend->amount / 100, 2, '.', ''),
            $adSpend->currency,
            $adSpend->starts_on->toDateString(),
            $adSpend->ends_on->toDateString(),
        );
    }

    /**
     * @param  array<string, array{name: string}>  $definitions
     * @param  array<int, mixed>  $keys
     */
    private function definitionNames(array $definitions, array $keys): string
    {
        return collect($keys)->map(fn (mixed $key): string => $definitions[$key]['name'] ?? (string) $key)->implode(', ') ?: 'None';
    }
}
