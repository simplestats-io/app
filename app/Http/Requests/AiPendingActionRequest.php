<?php

namespace App\Http\Requests;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class AiPendingActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $team = $this->user()?->currentTeam;
        $owner = $team instanceof Team ? $team->billingOwner : null;

        return $owner instanceof User && (bool) ($owner->sparkPlan()->options['AiAssistant'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'uuid'],
        ];
    }
}
