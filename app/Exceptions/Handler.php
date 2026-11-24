<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use League\OAuth2\Server\Exception\OAuthServerException;
use Sentry\Laravel\Integration;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Passport's token guard reports every rejected bearer token. On /mcp an expired
     * or revoked OAuth token reaches it and ends as a 401.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        OAuthServerException::class,
    ];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e): void {
            Integration::captureUnhandledException($e);
        });
    }
}
