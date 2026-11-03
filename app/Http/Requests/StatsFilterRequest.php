<?php

namespace App\Http\Requests;

use App\Enums\ComparisonType;
use App\Enums\StatsRangePreset;
use App\Enums\StatsRangeType;
use App\Helpers\AppHelper;
use App\Models\Campaign;
use App\Models\CustomStatsRangePreset;
use App\Models\Project;
use App\Models\StatsCustomEventName;
use App\Models\StatsCustomPropertyName;
use App\Models\StatsCustomPropertyValue;
use App\Models\StatsDeviceBrowser;
use App\Models\StatsDevicePlatform;
use App\Models\StatsDeviceType;
use App\Models\StatsLocationCity;
use App\Models\StatsLocationCountry;
use App\Models\StatsLocationRegion;
use App\Models\StatsPageEntry;
use App\Models\StatsTrackCampaign;
use App\Models\StatsTrackContent;
use App\Models\StatsTrackMedium;
use App\Models\StatsTrackReferer;
use App\Models\StatsTrackSource;
use App\Models\StatsTrackTerm;
use App\Rules\ValidTeamModel;
use App\Services\StatsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StatsFilterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // The frontend filters carry public uuids; everything behind this
        // boundary (validation, DTO, aggregation cache) works on internal ids.
        foreach (['project_id' => Project::class, 'campaign' => Campaign::class] as $key => $modelClass) {
            if (is_string($this->input($key)) && Str::isUuid($this->input($key))) {
                $this->merge([$key => AppHelper::resolvePublicId($modelClass, $this->input($key))]);
            }
        }

        $projects = $this->user()->currentTeam->projects();

        // make the tracking filter work and set the projectId manually if only one project exists...
        if ($projects->count() === 1) {
            $project = $projects->first();
            $this->merge([
                'project_id' => $project->id,
            ]);
        }

        // Members limited to selected projects have no "All" view (see
        // AbstractStatsDataProvider::loadTeamStats()), so default to their first project.
        if (empty($this->input('project_id')) && $this->user()->hasRestrictedProjectAccess($this->user()->currentTeam)) {
            $firstProjectId = $projects->orderBy('id')->value('id');

            if ($firstProjectId) {
                $this->merge([
                    'project_id' => $firstProjectId,
                ]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->sharedFilterRules(),
            // grouping by a custom property ("property" + property_name_id)
            // exists only on the dashboard, the public API keeps the static types
            'stats_type' => [
                'sometimes',
                'string',
                Rule::in([...StatsService::getStatsTypesFlat()->toArray(), 'property']),
            ],
            'project_id' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(Project::class),
            ],
            'group_type' => [
                'sometimes',
                'string',
                Rule::in([...array_keys(StatsService::getStatsTypes()->toArray()), 'property']),
            ],
            'lifetime' => [
                'sometimes',
                'boolean',
            ],
            'preset' => [
                'sometimes',
                'string',
                function ($attribute, $value, $fail): void {
                    if (StatsRangePreset::tryFrom($value) !== null) {
                        return;
                    }

                    if (Str::startsWith($value, 'custom_')) {
                        $exists = CustomStatsRangePreset::where('id', (int) Str::after($value, 'custom_'))
                            ->where('user_id', $this->user()->id)
                            ->exists();

                        if ($exists) {
                            return;
                        }
                    }

                    $fail('The selected preset is invalid.');
                },
            ],
        ];
    }

    /**
     * Shared validation rules used by both web and API filter requests.
     */
    public function sharedFilterRules(): array
    {
        return [
            'range_type' => ['sometimes', Rule::enum(StatsRangeType::class)],
            'range_amount' => ['sometimes', 'integer'],
            'range_start' => ['sometimes', 'date'],
            'cohort_start' => ['sometimes', 'nullable', 'date'],
            'cohort_end' => ['sometimes', 'nullable', 'date'],
            'cohort_label' => ['sometimes', 'nullable', 'string'],
            'comparison' => [
                'sometimes',
                new Enum(ComparisonType::class),
            ],
            'track_referer' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsTrackReferer::class),
            ],
            'track_source' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsTrackSource::class),
            ],
            'track_medium' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsTrackMedium::class),
            ],
            'track_campaign' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsTrackCampaign::class),
            ],
            'track_term' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsTrackTerm::class),
            ],
            'track_content' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsTrackContent::class),
            ],
            'location_country' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsLocationCountry::class),
            ],
            'location_region' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsLocationRegion::class),
            ],
            'location_city' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsLocationCity::class),
            ],
            'device_type' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsDeviceType::class),
            ],
            'device_platform' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsDevicePlatform::class),
            ],
            'device_browser' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsDeviceBrowser::class),
            ],
            'page_entry' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsPageEntry::class),
            ],
            'custom_event_name' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsCustomEventName::class),
            ],
            'property_name_id' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsCustomPropertyName::class),
            ],
            'property_value' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(StatsCustomPropertyValue::class),
            ],
            'campaign' => [
                'sometimes',
                'bail',
                'integer',
                new ValidTeamModel(Campaign::class),
            ],
            'stats_type' => [
                'sometimes',
                'string',
                Rule::in(StatsService::getStatsTypesFlat()->toArray()),
            ],
            'stats_sort' => [
                'sometimes',
                'string',
                Rule::in(['name', 'net', 'reg', 'visitors', 'cr', 'dau', 'total', 'triggered', 'arpv', 'arpu', 'arpdau', 'reg2dau', 'mrr', 'subscribers', 'arps', 'time_to_convert', 'nau', 'ret_c1', 'ret_c2', 'ret_c3']),
            ],
            'limit' => [
                'sometimes',
                'integer',
            ],
            // gates the per-dimension columns only the metric detail breakdown needs
            // (MRR snapshot, time-to-convert median, retention checkpoints; 'nau'
            // enables the NAU count plus the retention checkpoints)
            'metric' => [
                'sometimes',
                'string',
                Rule::in(['mrr', 'time_to_convert', 'retention', 'nau']),
            ],
        ];
    }
}
