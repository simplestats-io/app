<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // One-click unsubscribe (RFC 8058): mail clients POST without a session, the signed URL authorizes it.
        'lifecycle-mails/opt-out/*',
    ];
}
