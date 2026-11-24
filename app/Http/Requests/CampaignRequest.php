<?php

namespace App\Http\Requests;

use App\Helpers\AppHelper;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class CampaignRequest extends FormRequest
{
    /**
     * The project select sends this instead of a project uuid for a campaign that
     * spans all projects of the team.
     */
    public const string ALL_PROJECTS = 'all';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The frontend sends the project's public uuid. Only the explicit marker becomes
     * a team-wide campaign: an unknown uuid resolves to 0 and fails validation.
     */
    protected function prepareForValidation(): void
    {
        $projectId = $this->input('project_id');

        $this->merge([
            'project_id' => $projectId === self::ALL_PROJECTS ? null : AppHelper::resolvePublicId(Project::class, $projectId ?? 0),
        ]);
    }

    /**
     * Null means all projects, which is only open to members with access to every project.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accessibleProjectIds = AppHelper::accessibleProjectIds();

        return [
            'project_id' => [
                Rule::requiredIf($accessibleProjectIds !== null),
                'nullable',
                'integer',
                Rule::exists('projects', 'id')
                    ->where('team_id', AppHelper::getTeamId())
                    ->when($accessibleProjectIds !== null, fn (Exists $rule): Exists => $rule->whereIn('id', $accessibleProjectIds)),
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_id.required' => 'Select one of your projects. Campaigns for all projects need access to every project.',
        ];
    }
}
