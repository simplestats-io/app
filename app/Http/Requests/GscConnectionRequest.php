<?php

namespace App\Http\Requests;

use App\Exceptions\GscReconnectRequiredException;
use App\Models\GscConnection;
use App\Models\Project;
use App\Services\Gsc\GscApiService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class GscConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only the person who granted the Google access picks a property: their account may
        // read properties of other clients, which another editor of this project must not import.
        return $this->user()->can('update', $this->route('project'))
            && $this->connection()->user()->is($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'site_url' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                // Only properties the connected Google account can read, never an arbitrary url.
                try {
                    $sites = resolve(GscApiService::class)->sites($this->connection());
                } catch (GscReconnectRequiredException) {
                    $fail('Please reconnect Google Search Console.');

                    return;
                }

                if (! in_array($value, $sites, true)) {
                    $fail('This property is not available in the connected Google account.');
                }
            }],
        ];
    }

    public function connection(): GscConnection
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project->gscConnection()->firstOrFail();
    }
}
