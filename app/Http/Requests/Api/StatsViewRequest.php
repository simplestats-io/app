<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Only the envelope is validated strictly, the items are checked one by one in
 * StatsViewItemsSanitizer so a single broken entry does not reject the batch.
 */
class StatsViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'uuid'],
            'views' => ['required', 'array', 'min:1', 'max:1000'],
            'meta' => ['nullable', 'array'],
            'meta.mode' => ['nullable', 'string', 'in:worker,direct'],
        ];
    }
}
