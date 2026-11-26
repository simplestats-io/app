<?php

namespace App\Mcp\Tools\Concerns;

use App\Http\Requests\AdSpendRequest;
use App\Http\Requests\CampaignLinkRequest;
use App\Http\Requests\CampaignRequest;
use App\Models\AdSpend;
use App\Models\Campaign;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The campaign tools act as the signed-in user (OAuth connection or the in-app
 * assistant): role and project access are enforced by the campaign policies like in
 * the web UI, and an OAuth connection also needs the write scope for changes. The
 * unauthenticated local server, where no team scope applies, is refused.
 */
trait ManagesCampaigns
{
    use ResolvesMcpUser;

    protected const array CAMPAIGN_RELATIONS = ['project:id,name', 'statsTrackCampaigns:id,campaign_id,name', 'adSpends'];

    protected function campaignCaller(): User
    {
        return $this->signedInUser()
            ?? throw new AuthorizationException('Managing campaigns requires a signed-in user: connect the MCP server via OAuth.');
    }

    /**
     * Null (or 0) creates a campaign for all projects of the team.
     */
    protected function projectForNewCampaign(mixed $projectId): ?Project
    {
        $caller = $this->campaignCaller();
        $this->ensureWriteAccess();

        if ($caller->cannot('create', Campaign::class)) {
            throw new AuthorizationException('Your team role does not allow creating campaigns.');
        }

        $campaignRequest = new CampaignRequest;
        $projectId = blank($projectId) || (int) $projectId === 0 ? null : (int) $projectId;

        Validator::make(['project_id' => $projectId], ['project_id' => $campaignRequest->rules()['project_id']], $campaignRequest->messages())->validate();

        if ($projectId === null) {
            return null;
        }

        $project = Project::findOrFail($projectId);
        $this->ensureTracksPayments($project);

        return $project;
    }

    protected function ensureTracksPayments(?Project $project): void
    {
        if ($project !== null && ! $project->hasUsages([Project::USAGE_PAYMENTS])) {
            throw ValidationException::withMessages([
                'project_id' => "The project \"{$project->name}\" does not track payments, so campaigns are not available for it.",
            ]);
        }
    }

    protected function writableCampaign(string $uuid): Campaign
    {
        $caller = $this->campaignCaller();
        $this->ensureWriteAccess();

        $campaign = Campaign::query()->where('uuid', $uuid)->first()
            ?? throw ValidationException::withMessages(['campaign_id' => 'No campaign with this campaign_id exists in the accessible projects. Use list-campaigns to look it up.']);

        if ($caller->cannot('update', $campaign)) {
            throw new AuthorizationException('Your team role or project access does not allow changing this campaign.');
        }

        $this->ensureTracksPayments($campaign->project);

        return $campaign;
    }

    protected function writableAdSpend(string $uuid): AdSpend
    {
        $caller = $this->campaignCaller();
        $this->ensureWriteAccess();

        $adSpend = AdSpend::query()
            ->where('uuid', $uuid)
            ->whereHas('campaign')
            ->first()
            ?? throw ValidationException::withMessages(['ad_spend_id' => 'No ad spend entry with this ad_spend_id exists in the accessible projects. Use list-campaigns to look it up.']);

        if ($caller->cannot('update', $adSpend)) {
            throw new AuthorizationException('Your team role or project access does not allow changing this ad spend entry.');
        }

        $this->ensureTracksPayments($adSpend->campaign->project);

        return $adSpend;
    }

    /**
     * @return array<string, mixed>
     */
    protected function utmCampaignRules(string $key): array
    {
        return [
            $key => ['nullable', 'array', 'max:20'],
            "{$key}.*" => [...(new CampaignLinkRequest)->rules()['name'], 'distinct'],
        ];
    }

    /**
     * Ad spend is stored in cents, the tools take major units like the web form.
     * Spend is summed against team-currency revenue without conversion, so any
     * other currency would silently corrupt ROAS, CAC and profit.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function validatedAdSpend(Campaign $campaign, array $attributes): array
    {
        $teamCurrency = $campaign->team->currency;
        $attributes = [
            ...$attributes,
            'name' => AdSpendRequest::normalizeLabel($attributes['name'] ?? null),
            'utm_source' => AdSpendRequest::normalizeLabel($attributes['utm_source'] ?? null),
            'campaign_id' => $campaign->id,
            'currency' => strtoupper((string) ($attributes['currency'] ?? $teamCurrency)),
        ];

        Validator::make($attributes, [
            'name' => ['nullable', 'string', 'max:60'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'currency' => ['required', Rule::in([$teamCurrency])],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
        ], [
            'currency.in' => "Ad spend must be entered in the team currency ({$teamCurrency}). Convert the amount first.",
        ])->validate();

        return Validator::make([
            ...$attributes,
            'amount' => (int) round(((float) $attributes['amount']) * 100),
        ], (new AdSpendRequest)->rules())->validate();
    }

    /**
     * @return array<string, Type>
     */
    protected function adSpendSchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Optional label for the entry, e.g. the ad platform ("X", "Reddit"). Max 60 characters.'),
            'utm_source' => $schema->string()
                ->description('Optional utm_source the ads of this entry use (e.g. "reddit"). Links the spend to that source, so cost per visitor is shown per source. Matched case-insensitively.'),
            'amount' => $schema->number()
                ->description('Total spend of this entry in major currency units (e.g. 115 or 49.90, not cents). It is spread evenly across starts_on..ends_on.'),
            'currency' => $schema->string()
                ->description('ISO-4217 currency code. Must be the team currency (spend is not converted), which is also the default.'),
            'starts_on' => $schema->string()
                ->description('First day of the spend (YYYY-MM-DD).'),
            'ends_on' => $schema->string()
                ->description('Last day of the spend (YYYY-MM-DD), inclusive.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function campaignPayload(Campaign $campaign): array
    {
        $campaign->loadMissing(self::CAMPAIGN_RELATIONS);

        return [
            'campaign_id' => $campaign->getRouteKey(),
            'name' => $campaign->name,
            'project' => $campaign->spansAllProjects() ? 'All projects' : $campaign->project?->name,
            'project_id' => $campaign->project_id ?? 0,
            'all_projects' => $campaign->spansAllProjects(),
            'utm_campaigns' => $campaign->statsTrackCampaigns->pluck('name')->sort()->values()->all(),
            'ad_spends' => $campaign->adSpends
                ->sortBy('starts_on')
                ->map(fn (AdSpend $adSpend): array => [
                    'ad_spend_id' => $adSpend->getRouteKey(),
                    'name' => $adSpend->name,
                    'utm_source' => $adSpend->utm_source,
                    'amount' => round($adSpend->amount / 100, 2),
                    'currency' => $adSpend->currency,
                    'starts_on' => $adSpend->starts_on->toDateString(),
                    'ends_on' => $adSpend->ends_on->toDateString(),
                ])
                ->values()
                ->all(),
        ];
    }
}
