<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Project access for inviting/adding a team member and for updating one.
 * Email and role keep being validated by the Jetstream actions; this request
 * only covers the project selection and maps its public uuids to ids.
 */
class TeamMemberAccessRequest extends FormRequest
{
    public const ACCESS_ALL = 'all';

    public const ACCESS_SELECTED = 'selected';

    /**
     * The actions authorize again; checking here keeps validation (and its
     * project lookup) from running for users who cannot manage the team.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(
            $this->isMethod('post') ? 'addTeamMember' : 'updateTeamMember',
            $this->team(),
        );
    }

    protected function prepareForValidation(): void
    {
        // The add member form reports into its own error bag on the frontend.
        if ($this->isMethod('post')) {
            $this->errorBag = 'addTeamMember';
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project_access' => [
                'nullable',
                Rule::in([
                    self::ACCESS_ALL,
                    self::ACCESS_SELECTED,
                ]),
            ],
            'projects' => [
                Rule::requiredIf(fn (): bool => $this->restrictsProjects()),
                'array',
            ],
            'projects.*' => [
                'bail',
                'string',
                'uuid',
                Rule::exists('projects', 'uuid')->where('team_id', $this->team()->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'projects.required' => 'Select at least one project.',
            'projects.*.uuid' => 'One of the selected projects does not belong to this team.',
            'projects.*.exists' => 'One of the selected projects does not belong to this team.',
        ];
    }

    /**
     * The validated project restriction, or null for access to all projects.
     * Administrators always get all projects.
     *
     * @return array<int, int>|null
     */
    public function projectIds(): ?array
    {
        if (! $this->restrictsProjects()) {
            return null;
        }

        // Resolved within the managed team, which is not necessarily the
        // admin's current team that the global scopes would narrow to.
        return Project::query()
            ->withoutGlobalScopes()
            ->whereBelongsTo($this->team())
            ->whereIn('uuid', $this->validated('projects'))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function restrictsProjects(): bool
    {
        return $this->input('project_access') === self::ACCESS_SELECTED && $this->input('role') !== 'admin';
    }

    private function team(): Team
    {
        return $this->route('team');
    }
}
