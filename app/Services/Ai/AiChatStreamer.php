<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AiChatStreamer
{
    public function __construct(protected AiPendingActionService $pendingActions) {}

    /**
     * Stream agent events as SSE, translating provider failures into error
     * events instead of letting the exception dump Laravel's HTML error page
     * into the already-open stream (headers are sent before the first event).
     */
    public function response(iterable $events, string $conversationId, bool $usesTeamKey = false): StreamedResponse
    {
        return response()->stream(function () use ($events, $usesTeamKey) {
            $teamKeyHint = $usesTeamKey ? ' Your team uses its own AI API key, check the AI settings on your team page.' : '';

            try {
                foreach ($events as $event) {
                    yield 'data: '.($event)."\n\n";

                    foreach ($this->pendingActions->pullProposed() as $action) {
                        yield 'data: '.json_encode(['type' => 'pending_action', 'action' => $action])."\n\n";
                    }
                }
            } catch (RateLimitedException) {
                yield $this->errorEvent('The AI provider\'s rate limit is reached. Please wait a minute and try again.'.$teamKeyHint);
            } catch (ProviderOverloadedException) {
                yield $this->errorEvent('The AI provider is currently overloaded. Please try again in a few minutes.');
            } catch (InsufficientCreditsException) {
                yield $this->errorEvent($usesTeamKey
                    ? 'Your team\'s AI provider account is out of credits. Check the AI settings on your team page.'
                    : 'The AI provider account is out of credits. Please contact support.');
            } catch (ConnectionException) {
                yield $this->errorEvent('The AI provider could not be reached. Please try again in a moment.');
            } catch (Throwable $e) {
                report($e);

                yield $this->errorEvent('Something went wrong. Please try again.'.$teamKeyHint);
            }

            yield "data: [DONE]\n\n";
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'X-Conversation-Id' => $conversationId,
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function errorEvent(string $message): string
    {
        return 'data: '.json_encode(['type' => 'error', 'message' => $message])."\n\n";
    }
}
