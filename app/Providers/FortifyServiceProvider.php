<?php

namespace App\Providers;

use App\Actions\ConfirmPasswordOrPasskey;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\PasskeyConfirmationResponse;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Actions\ConfirmPassword;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Laravel\Passkeys\Passkeys;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ConfirmPassword::class, ConfirmPasswordOrPasskey::class);
        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Users marked for deletion (soft-deleted) keep their data during the grace
        // window but must not be able to log back in; an admin restores them via
        // app:restore-user. withTrashed() so the block does not silently depend on
        // the SoftDeletes global scope being applied here.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::withTrashed()
                ->where(Fortify::username(), $request->input(Fortify::username()))
                ->first();

            if (! $user || ! Hash::check($request->input('password'), $user->password)) {
                return null;
            }

            // Say why the account is blocked, but only to someone who proved they own
            // it. The generic "credentials do not match" reads as a forgotten password,
            // so returning users gave up on a still restorable account instead of
            // asking for it back. Password reset cannot help them either, the broker
            // does not find soft-deleted users.
            if ($user->trashed()) {
                throw $this->deactivatedAccountException(Fortify::username());
            }

            return $user;
        });

        Passkeys::usePasskeyModel(Passkey::class);

        // Passkey logins bypass authenticateUsing(), so the soft-delete block is
        // repeated here. A verified passkey proves ownership just like the
        // password does, which is why the deactivation notice may be shown.
        Passkeys::authorizeLoginUsing(function (Request $request, ?User $user): bool {
            if (! $user instanceof User) {
                return false;
            }

            if ($user->trashed()) {
                throw $this->deactivatedAccountException('credential');
            }

            return true;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by($request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip()));

        Fortify::registerView(function (Request $request) {
            if ($request->has('invitation_mail')) {
                session()->put('invitation', [
                    'invitationMail' => $request->invitation_mail ?? '',
                    'acceptInvitationLink' => $request->accept_invitation_link ?? '',
                    'teamName' => urldecode($request->team_name) ?? '',
                    'teamOwner' => urldecode($request->team_owner) ?? '',
                ]);
            }

            return Inertia::render('Auth/Register', [
                'params' => session('invitation') ?? [],
            ]);
        });

        Fortify::loginView(function () {
            $data = [
                'canResetPassword' => Route::has('password.request'),
                'status' => session('status'),
            ];

            if (! app()->isProduction()) {
                $data['testUser'] = config('app.test.user');
            }

            return Inertia::render('Auth/Login', $data);
        });
    }

    private function deactivatedAccountException(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => __('This account was deactivated because it was unused for a long time. Its data can still be restored, please contact :email.', ['email' => config('app.app_mail') ?: config('mail.from.address')]),
        ]);
    }
}
