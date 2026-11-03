<?php

namespace App\Http\Requests;

use App\Enums\DataImportType;
use App\Helpers\AppHelper;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\File;

class DataImportRequest extends FormRequest
{
    const MIMETYPES = ['xls', 'xlsx', 'csv', 'txt'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The frontend passes the project's public uuid (from the stats filters).
        $this->merge([
            'project_id' => AppHelper::resolvePublicId(Project::class, $this->input('project_id') ?? 0),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $accessibleProjectIds = AppHelper::accessibleProjectIds();

        return [
            'project_id' => [
                'required',
                'integer',
                Rule::exists(Project::class, 'id')->where(function ($query): void {
                    $query->where([
                        'team_id' => $this->user()->current_team_id,
                    ]);
                })->when($accessibleProjectIds !== null, fn (Exists $rule): Exists => $rule->whereIn('id', $accessibleProjectIds)),
            ],
            'type' => [
                'required',
                Rule::enum(DataImportType::class),
            ],
            'file' => [
                'required',
                File::types(self::MIMETYPES),
            ],
        ];
    }
}
