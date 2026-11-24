<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ManagesCampaigns;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
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

#[Description('Create a marketing campaign and optionally link the tracked utm_campaign values that belong to it (e.g. "black-week-2026"). Without project_id (or with 0) the campaign spans all projects of the team and counts the linked values in each of them, e.g. the same sale running on two shops. A campaign with the same name in the same scope is reused instead of duplicated. Once linked and with ad spend recorded (record-ad-spend), get-campaign-performance reports ROAS, CAC and profit for it. Only for projects that track payments.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
class CreateCampaign extends Tool
{
    use ManagesCampaigns;

    public function __construct(protected CampaignService $campaignService) {}

    public function shouldRegister(): bool
    {
        return ! $this->isReadOnlyConnection();
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'project_id' => ['nullable', 'integer', 'min:0'],
                ...$this->utmCampaignRules('utm_campaigns'),
            ]);

            $project = $this->projectForNewCampaign($request->get('project_id'));

            $campaign = DB::transaction(function () use ($project, $request): Campaign {
                $campaign = $this->campaignService->create($project?->id, $request->get('name'));

                foreach ($request->get('utm_campaigns') ?? [] as $utmCampaign) {
                    $this->campaignService->linkTrackCampaign($campaign, $utmCampaign);
                }

                return $campaign;
            });

            return Response::structured([
                'created' => $campaign->wasRecentlyCreated,
                'campaign' => $this->campaignPayload($campaign),
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not create the campaign. The error was reported, tell the user to try again.');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Display name of the campaign, e.g. "Black Week 2026".')
                ->required(),
            'project_id' => $schema->integer()
                ->description('Project the campaign belongs to. Omit or use 0 for a campaign across all projects of the team (only for members with access to every project).'),
            'utm_campaigns' => $schema->array()
                ->items($schema->string())
                ->description('Tracked utm_campaign values to link, exactly as they appear in the URLs (e.g. ["black-week-2026"]). Values without traffic yet are fine, they merge with later hits. A value can only belong to one campaign.'),
        ];
    }
}
