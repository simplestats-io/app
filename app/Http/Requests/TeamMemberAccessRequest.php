<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
     * Authorization stays in the actions (addTeamMember / updateTeamMember).
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The add member form reports into its own error bag on the frontend.
        if ($this->isMethod('post')) {
            $this->errorBag = 'addTeamMember';
        }

        $projectUuids = $this->collect('projects')
            ->filter(fn (mixed $uuid): bool => is_string($uuid) && Str::isUuid($uuid))
            ->values()
            ->all();

        // Resolved within the managed team, which is not necessarily the
        // admin's current team that the global scopes would narrow to.
        $this->merge([
            'project_ids' => Project::query()
                ->withoutGlobalScopes()
                ->whereBelongsTo($this->team())
                ->whereIn('uuid', $projectUuids)
                ->pluck('id')
                ->all(),
        ]);
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
                'string',
                Rule::exists('projects', 'uuid')->where('team_id', $this->team()->id),
            ],
            'project_ids' => ['array'],
            'project_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'projects.required' => 'Select at least one project.',
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

        return array_map(intval(...), $this->validated('project_ids'));
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
