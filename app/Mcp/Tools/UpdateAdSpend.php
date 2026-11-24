<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ManagesCampaigns;
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

#[Description('Correct an existing ad spend entry, e.g. when the final invoice differs or the campaign ran longer. Look up the ad_spend_id with list-campaigns. Only the given fields change.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
class UpdateAdSpend extends Tool
{
    use ManagesCampaigns;

    public function shouldRegister(): bool
    {
        return ! $this->isReadOnlyConnection();
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $request->validate(['ad_spend_id' => ['required', 'uuid']]);

            $adSpend = $this->writableAdSpend($request->get('ad_spend_id'));

            $adSpend->update($this->validatedAdSpend($adSpend->campaign, [
                'amount' => $request->get('amount') ?? $adSpend->amount / 100,
                'currency' => $request->get('currency') ?? $adSpend->currency,
                'starts_on' => $request->get('starts_on') ?? $adSpend->starts_on->toDateString(),
                'ends_on' => $request->get('ends_on') ?? $adSpend->ends_on->toDateString(),
            ]));

            return Response::structured([
                'ad_spend_id' => $adSpend->getRouteKey(),
                'campaign' => $this->campaignPayload($adSpend->campaign),
            ]);
        } catch (ValidationException $e) {
            return Response::error('Invalid arguments: '.collect($e->errors())->flatten()->implode(' '));
        } catch (AuthorizationException $e) {
            return Response::error($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return Response::error('Could not update the ad spend. The error was reported, tell the user to try again.');
        }
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ad_spend_id' => $schema->string()
                ->description('The ad_spend_id from list-campaigns or record-ad-spend.')
                ->required(),
            ...$this->adSpendSchema($schema),
        ];
    }
}
