<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ManagesCampaigns;
use App\Models\AdSpend;
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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Record an ad spend entry for a campaign: the total amount paid for a date range (e.g. 115 EUR on X from 2026-11-23 to 2026-11-30). The amount is spread evenly across the days. Every call adds a new entry, so check list-campaigns first and use update-ad-spend to correct an existing entry instead of recording it twice. Record one entry per ad platform with its utm_source, so the campaign page shows spend and cost per visitor per source.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class RecordAdSpend extends Tool
{
    use ManagesCampaigns;

    public function shouldRegister(): bool
    {
        return ! $this->isReadOnlyConnection();
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate(['campaign_id' => ['required', 'uuid']]);

            $campaign = $this->writableCampaign($request->get('campaign_id'));

            $adSpend = AdSpend::create($this->validatedAdSpend($campaign, [
                'name' => $request->get('name'),
                'utm_source' => $request->get('utm_source'),
                'amount' => $request->get('amount'),
                'currency' => $request->get('currency'),
                'starts_on' => $request->get('starts_on'),
                'ends_on' => $request->get('ends_on'),
            ]));

            return Response::structured([
                'ad_spend_id' => $adSpend->getRouteKey(),
                'campaign' => $this->campaignPayload($campaign),
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not record the ad spend. The error was reported, tell the user to try again.');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $fields = $this->adSpendSchema($schema);

        return [
            'campaign_id' => $schema->string()
                ->description('The campaign_id from list-campaigns or create-campaign.')
                ->required(),
            'name' => $fields['name'],
            'utm_source' => $fields['utm_source'],
            'amount' => $fields['amount']->required(),
            'currency' => $fields['currency'],
            'starts_on' => $fields['starts_on']->required(),
            'ends_on' => $fields['ends_on']->required(),
        ];
    }
}
