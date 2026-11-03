<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use App\Concerns\HasPublicUuid;
use App\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;
    use HasProjectAccessScope;
    use HasPublicUuid;
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'project_id',
        'name',
    ];

    protected $hidden = [
        'id',
        'team_id',
        'project_id',
    ];

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
     * @return HasMany<StatsTrackCampaign, $this>
     */
    public function statsTrackCampaigns(): HasMany
    {
        return $this->hasMany(StatsTrackCampaign::class);
    }

    /**
     * @return HasMany<AdSpend, $this>
     */
    public function adSpends(): HasMany
    {
        return $this->hasMany(AdSpend::class);
    }
}
