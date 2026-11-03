<?php

namespace App\Providers;

use App\Listeners\CreateTeamMemberNotifications;
use App\Listeners\DeleteTeamMemberNotifications;
use App\Listeners\EmailVerificationListener;
use App\Listeners\SetCurrentTeam;
use App\Listeners\SyncTeamMemberNotifications;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;
use Laravel\Jetstream\Events\TeamMemberAdded;
use Laravel\Jetstream\Events\TeamMemberRemoved;
use Laravel\Jetstream\Events\TeamMemberUpdated;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        Verified::class => [
            EmailVerificationListener::class,
        ],
        TeamMemberAdded::class => [
            SetCurrentTeam::class,
            CreateTeamMemberNotifications::class,
        ],
        TeamMemberRemoved::class => [
            DeleteTeamMemberNotifications::class,
        ],
        TeamMemberUpdated::class => [
            SyncTeamMemberNotifications::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        Event::listen(function (TakeImpersonation $event): void {
            session()->put([
                'password_hash_sanctum' => $event->impersonated->getAuthPassword(),
            ]);
        });

        Event::listen(function (LeaveImpersonation $event): void {
            session()->remove('password_hash_web');
            session()->put([
                'password_hash_sanctum' => $event->impersonator->getAuthPassword(),
            ]);
            Auth::setUser($event->impersonator);
        });

        Event::listen(Login::class, function ($event): void {
            $event->user->update([
                'previous_login_at' => $event->user->last_login_at,
                'last_login_at' => now(),
                'inactive_since' => null,
                'login_summary_pending' => true,
            ]);
        });
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
