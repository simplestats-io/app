<?php

namespace App\Http\Requests;

use App\Helpers\AppHelper;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The frontend sends the project's public uuid.
        $this->merge([
            'project_id' => AppHelper::resolvePublicId(Project::class, $this->input('project_id') ?? 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accessibleProjectIds = AppHelper::accessibleProjectIds();

        return [
            'project_id' => [
                'required',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('team_id', AppHelper::getTeamId())
                    ->when($accessibleProjectIds !== null, fn (Exists $rule): Exists => $rule->whereIn('id', $accessibleProjectIds)),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
