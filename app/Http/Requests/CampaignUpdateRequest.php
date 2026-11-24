<?php

namespace App\Http\Requests;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class CampaignUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', self::uniqueName($this->route('campaign'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Another campaign of the same scope already uses this name.',
        ];
    }

    /**
     * Names are unique per project, and per team among the campaigns for all projects.
     */
    public static function uniqueName(Campaign $campaign): Unique
    {
        return Rule::unique('campaigns', 'name')
            ->where('team_id', $campaign->team_id)
            ->where('project_id', $campaign->project_id)
            ->ignore($campaign);
    }
}
