<?php

namespace App\Models;

use App\Concerns\HasPublicUuid;
use App\Concerns\HasTeamScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSpend extends Model
{
    use HasFactory;
    use HasPublicUuid;
    use HasTeamScope;

    protected $fillable = [
        'team_id',
        'campaign_id',
        'name',
        'utm_source',
        'amount',
        'currency',
        'starts_on',
        'ends_on',
    ];

    protected $hidden = [
        'id',
        'team_id',
        'campaign_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Cents allocated to [$windowStart, $windowEnd] by day overlap, since a
     * spend entry's budget is spread evenly across its own run (starts_on..ends_on).
     */
    public function allocatedFor(CarbonImmutable $windowStart, CarbonImmutable $windowEnd): int
    {
        $start = $this->starts_on->startOfDay();
        $end = $this->ends_on->startOfDay();

        $totalDays = $start->diffInDays($end) + 1;

        $overlapStart = $start->max($windowStart->startOfDay());
        $overlapEnd = $end->min($windowEnd->startOfDay());

        if ($overlapStart->greaterThan($overlapEnd)) {
            return 0;
        }

        $overlapDays = $overlapStart->diffInDays($overlapEnd) + 1;

        return (int) round($this->amount * $overlapDays / $totalDays);
    }
}
