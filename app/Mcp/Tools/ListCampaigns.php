<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ManagesCampaigns;
use App\Models\Campaign;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('List the marketing campaigns with their campaign_id, project, linked tracked utm_campaign values and ad spend entries (with ad_spend_id). Call this before creating a campaign or recording ad spend to avoid duplicates, and to look up the ids the write tools need. For performance numbers (ROAS, CAC, profit) use get-campaign-performance.')]
#[IsReadOnly]
#[IsIdempotent]
class ListCampaigns extends Tool
{
    use ManagesCampaigns;

    private const int LIST_LIMIT = 100;

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate([
                'project_id' => ['nullable', 'integer', 'min:0'],
            ]);

            $this->campaignCaller();

            $projectId = (int) ($request->get('project_id') ?? 0);

            $campaigns = Campaign::query()
                ->with(self::CAMPAIGN_RELATIONS)
                ->when($projectId !== 0, fn ($query) => $query->where('project_id', $projectId))
                ->orderBy('name')
                ->limit(self::LIST_LIMIT + 1)
                ->get();

            return Response::structured([
                'campaigns' => $campaigns->take(self::LIST_LIMIT)->map($this->campaignPayload(...))->all(),
                'truncated' => $campaigns->count() > self::LIST_LIMIT,
                'note' => $campaigns->isEmpty()
                    ? 'No campaigns exist yet. Create one with create-campaign.'
                    : 'Ad spend amounts are in major currency units. A campaign counts the traffic of every linked utm_campaign value within its own project; one with all_projects=true counts it in every project of the team.',
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not list campaigns. The error was reported, tell the user to try again.');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('Only list the campaigns of this project. Omit or use 0 for all campaigns, including the ones that span all projects.'),
        ];
    }
}
