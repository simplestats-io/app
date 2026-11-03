<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Jobs\QueuedVerifyEmailJob;
use App\Services\SubscriptionService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use JoelButcher\Socialstream\HasConnectedAccounts;
use JoelButcher\Socialstream\SetsProfilePhotoFromUrl;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Ai\Concerns\HasConversations;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Jetstream\HasTeams;
use Laravel\Jetstream\Jetstream;
use Laravel\Sanctum\HasApiTokens;
use Spatie\DeletedModels\Models\Concerns\KeepsDeletedModels;

abstract class UserBase extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    use HasApiTokens;
    use HasConnectedAccounts;
    use HasConversations;
    use HasFactory;
    use HasProfilePhoto {
        HasProfilePhoto::profilePhotoUrl as getPhotoUrl;
    }
    use HasPublicUuid;
    use HasTeams;
    use Impersonate;

    // SoftDeletes and KeepsDeletedModels both declare restore()/restoreQuietly();
    // let SoftDeletes win (un-trash) and keep the archive-restore under an alias.
    use KeepsDeletedModels, SoftDeletes {
        SoftDeletes::restore insteadof KeepsDeletedModels;
        SoftDeletes::restoreQuietly insteadof KeepsDeletedModels;
        KeepsDeletedModels::restore as restoreFromArchive;
        KeepsDeletedModels::restoreQuietly as restoreQuietlyFromArchive;
    }
    use Notifiable;
    use PasskeyAuthenticatable;
    use SetsProfilePhotoFromUrl;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'id',
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
        // Internal state that must not leak when users are serialized as team
        // members/owners; the curated auth.user share exposes what the frontend
        // needs (see App\Http\Middleware\ShareInertiaData).
        'settings',
        'is_admin',
        'current_team_id',
        'email_verified_at',
        'last_login_at',
        'previous_login_at',
        'login_summary_pending',
        'inactive_since',
        'deleted_at',
    ];

    /**
     * Memo for accessibleProjectIds(): it backs a global scope that runs on
     * every project-owned query, so the pivot lookup happens once per team.
     *
     * @var array<int, array<int, int>|null>
     */
    protected array $accessibleProjectIdsByTeam = [];

    // Archive into deleted_models only on the final forceDelete (the purge job),
    // not on the soft delete that opens the grace window.
    public function shouldKeep(): bool
    {
        return $this->isForceDeleting();
    }

    // Hex instead of the package's raw binary HMAC: iOS Safari mangles binary
    // user handles as UTF-8 and every later login fails (laravel/passkeys-server#41).
    // Never change this value, it is stored with every registered passkey.
    public function getPasskeyUserHandle(): string
    {
        return hash_hmac('sha256', $this->getTable().'|'.$this->getKey(), config('passkeys.user_handle_secret'));
    }

    const SESSION_SETTINGS_KEY = 'temp_user_settings_';

    private function useSessionSettings(): bool
    {
        return app()->environment('demo') || $this->isImpersonated();
    }

    /**
     * Get the user's settings (from session in demo mode or when impersonating, otherwise from database).
     */
    protected function settings(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                $dbSettings = json_decode($value, true) ?? [];

                if ($this->useSessionSettings()) {
                    return session()->get(self::SESSION_SETTINGS_KEY.$this->id, $dbSettings);
                }

                return $dbSettings;
            }
        );
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    protected function getPlanConfig()
    {
        if (config('app.app_type') !== 'saas') {
            return self::selfHostedPlanConfig();
        }

        $plans = config('spark.billables.user.plans', []);

        if (empty($plans)) {
            return [];
        }

        // local every user gets the highest plan by default :D
        return (! app()->isProduction() || in_array($this->id, config('app.plan_auto_max_users'))) ? $plans[count($plans) - 1] : $plans[0];
    }

    /**
     * @return array{name: string, short_description: string, features: array<string, string>, options: array<string, int|bool>}
     */
    public static function selfHostedPlanConfig(): array
    {
        return [
            'name' => SubscriptionService::PLAN_SELF_HOSTED,
            'short_description' => 'Analytics without any limits.',
            'features' => [
                Project::class => 'Unlimited Projects',
                'Events' => 'Unlimited Monthly Events',
                'MultiCurrency' => 'Multi Currency Support',
                DataImport::class => 'Data Import',
                'AiAssistant' => 'AI Assistant',
            ],
            'options' => [
                Project::class => SubscriptionService::UNLIMITED_AMOUNT,
                'Events' => SubscriptionService::UNLIMITED_AMOUNT,
                'ApiRateLimit' => (int) config('app.self_hosted_api_rate_limit'),
                'MultiCurrency' => true,
                DataImport::class => true,
                'AiAssistant' => true,
            ],
        ];
    }

    /**
     * Get the URL to the user's profile photo.
     */
    protected function profilePhotoUrl(): Attribute
    {
        return filter_var($this->profile_photo_path, FILTER_VALIDATE_URL)
            ? Attribute::get(fn () => $this->profile_photo_path)
            : $this->getPhotoUrl();
    }

    public function hasVerifiedEmail(): bool
    {
        if (app()->isLocal()) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    public function sendEmailVerificationNotification(): void
    {
        // dispactches the job to the queue passing it this User object
        dispatch(new QueuedVerifyEmailJob($this));
    }

    // Compatibility for auth()->team() checks in request/validator classes,
    // Which may be performed by the auth project (api) or the auth user (normal request)
    public function team(): BelongsTo
    {
        if (is_null($this->current_team_id) && $this->id) {
            $fallback = $this->personalTeam() ?? $this->allTeams()->first();
            if ($fallback) {
                $this->switchTeam($fallback);
            }
        }

        return $this->belongsTo(Jetstream::teamModel(), 'current_team_id');
    }

    public function canImpersonate()
    {
        return $this->isAdmin();
    }

    public function isAdmin(): bool
    {
        return $this->is_admin == 1;
    }

    public function canAccessPanel($panel): bool
    {
        return $this->isAdmin();
    }

    public function updateSettings($key, $value): void
    {
        $settings = $this->settings;
        Arr::set($settings, $key, $value);

        if ($this->useSessionSettings()) {
            session()->put(self::SESSION_SETTINGS_KEY.$this->id, $settings);
        } else {
            $this->update(['settings' => $settings]);
        }
    }

    public function makeAdmin()
    {
        $this->is_admin = 1;
        $this->save();
    }

    /**
     * Mirrors the Jetstream relation, plus the per-member project restriction.
     *
     * @return BelongsToMany<Team, $this, Membership, 'membership'>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->using(Membership::class)
            ->withPivot('role', 'project_ids')
            ->withTimestamps()
            ->as('membership');
    }

    /**
     * The project ids this user may access in the given team, or null for all
     * of them. The owner and administrators always see every project; editors
     * and viewers can be limited to a selection by a team administrator.
     *
     * Takes any Model like Jetstream's team relations (Jetstream::teamModel()).
     *
     * @return array<int, int>|null
     */
    public function accessibleProjectIds(Model|int $team): ?array
    {
        $teamId = (int) ($team instanceof Model ? $team->getKey() : $team);

        if (array_key_exists($teamId, $this->accessibleProjectIdsByTeam)) {
            return $this->accessibleProjectIdsByTeam[$teamId];
        }

        if ((int) Team::query()->whereKey($teamId)->value('user_id') === $this->id) {
            return $this->accessibleProjectIdsByTeam[$teamId] = null;
        }

        $membership = Membership::query()
            ->select([
                'role',
                'project_ids',
            ])
            ->where('team_id', $teamId)
            ->where('user_id', $this->id)
            ->first();

        if ($membership === null) {
            return $this->accessibleProjectIdsByTeam[$teamId] = [];
        }

        if ($membership->role === 'admin' || $membership->project_ids === null) {
            return $this->accessibleProjectIdsByTeam[$teamId] = null;
        }

        return $this->accessibleProjectIdsByTeam[$teamId] = array_map(intval(...), $membership->project_ids);
    }

    public function hasRestrictedProjectAccess(Model|int|null $team): bool
    {
        return $team !== null && $this->accessibleProjectIds($team) !== null;
    }

    public function canAccessProject(Project $project): bool
    {
        $projectIds = $this->accessibleProjectIds($project->team_id);

        return $projectIds === null || in_array($project->id, $projectIds, true);
    }

    /**
     * @return HasMany<CustomStatsRangePreset, $this>
     */
    public function customStatsRangePresets(): HasMany
    {
        return $this->hasMany(CustomStatsRangePreset::class)->orderBy('sort_order');
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'previous_login_at' => 'immutable_datetime',
            'login_summary_pending' => 'boolean',
            'settings' => 'array',
        ];
    }
}
