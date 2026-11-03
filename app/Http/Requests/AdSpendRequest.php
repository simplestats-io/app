<?php

namespace App\Http\Requests;

use App\Helpers\AppHelper;
use App\Models\AdSpend;
use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class AdSpendRequest extends FormRequest
{
    /**
     * Ad spend belongs to a project through its campaign, so editing it needs
     * update rights on that campaign (role and project access). A campaign the
     * user cannot see is left to the validation rules below.
     */
    public function authorize(): bool
    {
        $adSpend = $this->route('ad_spend');

        if ($adSpend instanceof AdSpend && $this->user()->cannot('update', $adSpend)) {
            return false;
        }

        $campaign = Campaign::find($this->input('campaign_id'));

        return $campaign === null || $this->user()->can('update', $campaign);
    }

    /**
     * Stored amounts are integer cents; the form sends a major-unit value.
     * The campaign reference arrives as the campaign's public uuid.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('amount')) {
            $this->merge(['amount' => (int) round(((float) $this->amount) * 100)]);
        }

        $this->merge([
            'campaign_id' => AppHelper::resolvePublicId(Campaign::class, $this->input('campaign_id') ?? 0),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $teamId = AppHelper::getTeamId();
        $accessibleProjectIds = AppHelper::accessibleProjectIds();

        return [
            'campaign_id' => [
                'required',
                'integer',
                Rule::exists('campaigns', 'id')
                    ->where('team_id', $teamId)
                    ->when($accessibleProjectIds !== null, fn (Exists $rule): Exists => $rule->whereIn('project_id', $accessibleProjectIds)),
            ],
            'amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ];
    }
}
