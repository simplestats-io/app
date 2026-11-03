<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Jetstream\TeamInvitation as JetstreamTeamInvitation;

class TeamInvitation extends JetstreamTeamInvitation
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'role',
        'project_ids',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_ids' => 'array',
        ];
    }

    /**
     * Get the team that the invitation belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
