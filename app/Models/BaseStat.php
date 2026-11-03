<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use App\Concerns\HasProjectScope;
use App\Concerns\HasTeamScope;
use App\Enums\StatsRangeType;
use Carbon\Month;
use Carbon\WeekDay;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

abstract class BaseStat extends Model
{
    use HasFactory;
    use HasProjectAccessScope;
    use HasProjectScope;
    use HasTeamScope;

    public $timestamps = false;

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
     * stats have a combined primary key, and we need it for updateOrCreate to work...
     *
     * @see https://github.com/laravel/framework/issues/55253
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query->where('id', $this->id)
            ->where('project_id', $this->project_id);
    }

    protected function time(): Attribute
    {
        return Attribute::make(
            set: fn (DateTimeInterface|WeekDay|Month|string|int|float|null $value): array => [
                'time' => Carbon::parse($value)->tz(auth()->user()->team->timezone)->format(StatsRangeType::DATE_FORMAT),
                'time_utc' => Carbon::parse($value)->tz('UTC')->format(StatsRangeType::DATE_FORMAT),
            ],
        );
    }

    /**
     * The attributes that should be cast to native types.
     */
    protected function casts(): array
    {
        return [
            'time' => 'datetime',
        ];
    }
}
