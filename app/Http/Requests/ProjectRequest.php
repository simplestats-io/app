<?php

namespace App\Http\Requests;

use App\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the domain to a bare host (strip scheme, path, query).
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('domain')) {
            return;
        }

        $input = trim($this->input('domain'));
        $candidate = Str::contains($input, '://') ? $input : 'https://'.$input;
        $host = parse_url($candidate, PHP_URL_HOST);

        $this->merge(['domain' => $host ?: $input]);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:30'],
            'domain' => ['required', 'string', 'max:255', 'regex:/^(localhost|([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,})$/i'],
            'usage' => [
                'required',
                'integer',
                'min:1',
                'max:'.Project::getUsageBitsCount(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $required = Project::USAGE_USERS | Project::USAGE_PAYMENTS;

                    if (($value & Project::USAGE_SUBSCRIPTION) && (($value & $required) !== $required)) {
                        $fail('Subscription tracking requires both Registrations and Payments.');
                    }

                    if (($value & Project::USAGE_PAYMENTS) && ! ($value & (Project::USAGE_VISITORS | Project::USAGE_USERS))) {
                        $fail('Payments must be linked to Visitors or Registrations.');
                    }
                },
            ],
            'flags' => [
                'sometimes',
                'integer',
                'min:0',
                'max:'.Project::getFlagBitsCount(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    $required = Project::USAGE_USERS | Project::USAGE_PAYMENTS;

                    if (($value & Project::FLAG_PAID_ONLY) && (((int) $this->input('usage') & $required) !== $required)) {
                        $fail('The "paid users only" option requires both Registrations and Payments.');
                    }
                },
            ],
        ];
    }
}
