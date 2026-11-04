<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->hidden[] = 'token_plain';
        $this->casts['token_plain'] = 'encrypted';
    }

    /**
     * @return Attribute<string, never>
     */
    protected function tokenPlainPart(): Attribute
    {
        return Attribute::make(
            get: fn (): string => explode('|', $this->token_plain, 2)[1],
        );
    }
}
