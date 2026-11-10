<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected $fillable = [
        'subject',
        'body',
        'last_sent_at',
        'scheduled_at',
        'recipients',
    ];

    protected function casts(): array
    {
        return [
            'last_sent_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'recipients' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_id')->withTrashed();
    }

    /**
     * Persist the latest version of a sent announcement: the loaded record wins,
     * otherwise an identical subject updates its record, otherwise a new one is created.
     */
    public static function recordSend(string $subject, string $body, ?int $loadedId = null): self
    {
        $announcement = static::resolveForSave($subject, $loadedId);

        $announcement->fill([
            'subject' => $subject,
            'body' => $body,
            'last_sent_at' => now(),
            'scheduled_at' => null,
        ])->save();

        return $announcement;
    }

    /**
     * @param  array{admins_only?: bool, include_deleted_users?: bool, custom_emails?: array<string>}  $recipients
     */
    public static function recordSchedule(string $subject, string $body, CarbonImmutable $scheduledAt, array $recipients, User $scheduledBy, ?int $loadedId = null): self
    {
        $announcement = static::resolveForSave($subject, $loadedId);

        $announcement->fill([
            'subject' => $subject,
            'body' => $body,
            'scheduled_at' => $scheduledAt,
            'recipients' => $recipients,
        ]);
        $announcement->scheduledBy()->associate($scheduledBy);
        $announcement->save();

        return $announcement;
    }

    protected static function resolveForSave(string $subject, ?int $loadedId): self
    {
        return ($loadedId ? static::find($loadedId) : null)
            ?? static::where('subject', $subject)->first()
            ?? new static;
    }
}
