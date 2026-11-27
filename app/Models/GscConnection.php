<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use App\Concerns\HasTeamScope;
use Database\Factories\GscConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GscConnection extends Model
{
    /** @use HasFactory<GscConnectionFactory> */
    use HasFactory;

    use HasProjectAccessScope;
    use HasTeamScope;

    protected $guarded = [];

    protected $hidden = ['id', 'team_id', 'project_id', 'user_id', 'access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_synced_until_date' => 'date',
            'reconnect_required_at' => 'datetime',
        ];
    }

    /**
     * The feature needs the operator's own Google OAuth client, so it stays hidden on
     * self-hosted installations without one.
     */
    public static function isAvailable(): bool
    {
        return filled(config('services.google_search_console.client_id')) && filled(config('services.google_search_console.client_secret'));
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->subMinute()->isPast();
    }

    public function markReconnectRequired(): void
    {
        if ($this->reconnect_required_at === null) {
            $this->touch('reconnect_required_at');
        }
    }

    public function needsSiteSelection(): bool
    {
        return $this->site_url === null && $this->reconnect_required_at === null;
    }

    public function isSyncable(): bool
    {
        return $this->site_url !== null && $this->reconnect_required_at === null;
    }
}
