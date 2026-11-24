<?php

namespace App\Mcp\Tools;

use App\Http\Requests\CampaignUpdateRequest;
use App\Mcp\Tools\Concerns\ManagesCampaigns;
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

#[Description('Rename a campaign and/or link more tracked utm_campaign values to it. Look up the campaign_id with list-campaigns. Linking never removes existing links; unlinking is only possible in the app.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
class UpdateCampaign extends Tool
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
                'campaign_id' => ['required', 'uuid'],
                'name' => ['required_without:link_utm_campaigns', 'nullable', 'string', 'max:255'],
                ...$this->utmCampaignRules('link_utm_campaigns'),
            ]);

            $campaign = $this->writableCampaign($request->get('campaign_id'));

            $request->validate([
                'name' => [CampaignUpdateRequest::uniqueName($campaign)],
            ], [
                'name.unique' => $campaign->spansAllProjects()
                    ? 'The team already has a campaign for all projects with this name.'
                    : 'The project already has a campaign with this name.',
            ]);

            DB::transaction(function () use ($campaign, $request): void {
                if (filled($request->get('name'))) {
                    $this->campaignService->rename($campaign, $request->get('name'));
                }

                foreach ($request->get('link_utm_campaigns') ?? [] as $utmCampaign) {
                    $this->campaignService->linkTrackCampaign($campaign, $utmCampaign);
                }
            });

            return Response::structured([
                'campaign' => $this->campaignPayload($campaign),
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not update the campaign. The error was reported, tell the user to try again.');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'campaign_id' => $schema->string()
                ->description('The campaign_id from list-campaigns or create-campaign.')
                ->required(),
            'name' => $schema->string()
                ->description('New display name. Omit to keep the current one.'),
            'link_utm_campaigns' => $schema->array()
                ->items($schema->string())
                ->description('Tracked utm_campaign values to link additionally, exactly as they appear in the URLs. A value can only belong to one campaign.'),
        ];
    }
}
