<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use App\Concerns\HasPublicUuid;
use App\Concerns\HasTeamScope;
use App\Enums\DataImportType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Junges\TrackableJobs\Models\TrackedJob;

class DataImport extends Model
{
    use HasFactory;
    use HasProjectAccessScope;
    use HasPublicUuid;
    use HasTeamScope;

    protected $hidden = [
        'id',
        'team_id',
        'project_id',
        'created_by',
    ];

    protected $appends = [
        'rows_progress',
    ];

    protected function rowsProgress(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round(($this->rows_imported / $this->rows_max) * 100),
        );

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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    /**
     * @return MorphOne<TrackedJob, $this>
     */
    public function trackedJob(): MorphOne
    {
        return $this->morphOne(TrackedJob::class, 'trackable');
    }

    protected function casts(): array
    {
        return [
            'type' => DataImportType::class,
            'rolled_back_at' => 'datetime',
        ];
    }
}
