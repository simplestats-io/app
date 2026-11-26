<?php

use Illuminate\Support\Facades\Route;
use JoelButcher\Socialstream\Http\Controllers\Inertia\ConnectedAccountController;
use JoelButcher\Socialstream\Http\Controllers\Inertia\PasswordController;
use JoelButcher\Socialstream\Http\Controllers\Inertia\UpdateUserProfilePhotoController;
use JoelButcher\Socialstream\Http\Controllers\OAuthController;
use JoelButcher\Socialstream\Socialstream;
use Laravel\Jetstream\Jetstream;

// Unknown providers (e.g. clients guessing /oauth/authorize, Passport lives under mcp/oauth) must 404 instead of
// failing inside Socialite. "(?!)" never matches, for self-hosted installs without providers.
$providerPattern = collect(Socialstream::providers())->pluck('id')->map(fn (string $id): string => preg_quote($id, '#'))->implode('|') ?: '(?!)';

Route::group(['middleware' => config('socialstream.middleware', ['web'])], function () use ($providerPattern): void {
    Route::get('/oauth/{provider}', [OAuthController::class, 'redirect'])->where('provider', $providerPattern)->name('oauth.redirect');
    Route::match(['get', 'post'], '/oauth/{provider}/callback', [OAuthController::class, 'callback'])->where('provider', $providerPattern)->name('oauth.callback');

    Route::delete('/user/connected-account/{id}', [ConnectedAccountController::class, 'destroy'])
        ->middleware(['auth'])
        ->name('connected-accounts.destroy');

    Route::post('/user/set-password', [PasswordController::class, 'store'])
        ->middleware(['auth'])
        ->name('user-password.set');

    if (Socialstream::hasProviderAvatarsFeature() && Jetstream::managesProfilePhotos()) {
        Route::put('/user/profile-photo', [UpdateUserProfilePhotoController::class, '__invoke'])
            ->middleware(['auth'])
            ->name('user-profile-photo.set');
    }
});
