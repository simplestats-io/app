<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use App\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StatsCustomPropertyValue extends Model
{
    use HasFactory;
    use HasProjectAccessScope;
    use HasTeamScope;

    protected $guarded = false;

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
     * @return BelongsTo<StatsCustomPropertyName, $this>
     */
    public function name(): BelongsTo
    {
        return $this->belongsTo(StatsCustomPropertyName::class, 'stats_custom_property_name_id');
    }

    /**
     * @return HasMany<StatsCustomProperty, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(StatsCustomProperty::class);
    }
}
