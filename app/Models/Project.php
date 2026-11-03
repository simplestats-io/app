<?php

namespace App\Models;

use App\Concerns\HasApiPlainTokens;
use App\Concerns\HasProjectAccessScope;
use App\Concerns\HasPublicUuid;
use App\Concerns\HasTeamScope;
use App\Contracts\NotifiableToUser;
use App\Enums\UserNotificationFrequency;
use App\Enums\UserNotificationType;
use App\Events\ProjectCreated;
use App\Events\ProjectDeleted;
use App\Observers\ProjectObserver;
use App\ValueObjects\UserNotificationTypeObject;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

#[ObservedBy(ProjectObserver::class)]
class Project extends Model implements AuthenticatableContract, NotifiableToUser
{
    use Authenticatable;
    use HasApiPlainTokens;
    use HasFactory;
    use HasProjectAccessScope;
    use HasPublicUuid;
    use HasTeamScope;

    const TOKEN_NAME = 'API Token';

    const USAGE_VISITORS = 1;

    const USAGE_USERS = 2;

    const USAGE_PAYMENTS = 4;

    // Bit value 8 was previously the retired "events" bit, cleared from all rows by
    // the 2026_06_11 migration, so reusing it for subscriptions is safe.
    const USAGE_SUBSCRIPTION = 8;

    const FLAG_PAID_ONLY = 1;

    protected $hidden = [
        'id',
        'team_id',
    ];

    protected $dispatchesEvents = [
        'created' => ProjectCreated::class,
        'deleted' => ProjectDeleted::class,
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function projectAccessColumn(): string
    {
        return 'id';
    }

    /**
     * @return MorphMany<UserNotification, $this>
     */
    public function userNotifications(): MorphMany
    {
        return $this->morphMany(UserNotification::class, 'notifiable');
    }

    /**
     * Report and alert relations only yield recipients with a live account: a soft-deleted
     * user keeps their rows for a possible restore, but must not be notified (and their
     * `user` relation resolves to null).
     */
    public function userReportNotifications(): MorphMany
    {
        return $this->userNotifications()->where('type', UserNotificationType::PROJECT_REPORT->value)->has('user');
    }

    public function userAlertNotifications(): MorphMany
    {
        return $this->userNotifications()->where('type', UserNotificationType::PROJECT_ALERT->value)->has('user');
    }

    public function hasUsages(array $bits): bool
    {
        foreach ($bits as $bit) {
            if (! ($bit & $this->usage)) {
                return false;
            }
        }

        return true;
    }

    public function hasFlags(array $bits): bool
    {
        foreach ($bits as $bit) {
            if (! ($bit & $this->flags)) {
                return false;
            }
        }

        return true;
    }

    public static function getUsages(): Collection
    {
        return collect([
            'visitors' => [
                'bit' => self::USAGE_VISITORS,
                'name' => 'Visitors',
                'descr' => 'Track anonymous visitors with UTMs, location, devices, etc.',
            ],
            'users' => [
                'bit' => self::USAGE_USERS,
                'name' => 'Registrations',
                'descr' => 'Track user signups and logins, including timestamps.',
            ],
            'payments' => [
                'bit' => self::USAGE_PAYMENTS,
                'name' => 'Payments',
                'descr' => 'Track payments and link them back to visitors or registrations.',
            ],
            'subscription' => [
                'bit' => self::USAGE_SUBSCRIPTION,
                'name' => 'Subscriptions',
                'descr' => 'Track subscriptions (MRR/ARR). Requires Registrations and Payments.',
            ],
        ]);
    }

    public static function getUsageBitsCount(): int
    {
        return self::getUsages()->sum('bit');
    }

    /**
     * @return Collection<string, array{bit: int, name: string, descr: string}>
     */
    public static function getFlags(): Collection
    {
        return collect([
            'paid_only' => [
                'bit' => self::FLAG_PAID_ONLY,
                'name' => 'Paid users only',
                'descr' => 'Every registered user is a paying customer (no free plan or unpaid trial). Used for better metrics calculation.',
            ],
        ]);
    }

    public static function getFlagBitsCount(): int
    {
        return self::getFlags()->sum('bit');
    }

    public function getDefaultUserNotificationTypes(): Collection
    {
        return collect([
            new UserNotificationTypeObject(
                type: UserNotificationType::PROJECT_REPORT,
                frequency: UserNotificationFrequency::WEEKLY,
                settings: [
                    'tracking' => collect([
                        'visitors' => $this->hasUsages([self::USAGE_VISITORS]),
                        'users' => $this->hasUsages([self::USAGE_USERS]),
                        'payments' => $this->hasUsages([self::USAGE_PAYMENTS]),
                    ])->filter()->all(),
                    'types' => [
                        StatsTrackReferer::class,
                        StatsPageEntry::class,
                        StatsLocationCountry::class,
                        StatsDevicePlatform::class,
                    ],
                ]
            ),
            new UserNotificationTypeObject(
                type: UserNotificationType::PROJECT_ALERT,
                frequency: null,
                settings: [
                    'tracking' => collect([
                        'registrations' => $this->hasUsages([self::USAGE_USERS]),
                        'payments' => $this->hasUsages([self::USAGE_PAYMENTS]),
                    ])->filter()->all(),
                ]
            ),
        ]);
    }

    protected function casts(): array
    {
        return [
            'stats_starting_at' => 'date',
        ];
    }
}
