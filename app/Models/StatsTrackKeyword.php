<?php

namespace App\Models;

use App\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;

class StatsTrackKeyword extends Model
{
    use HasTeamScope;

    public $timestamps = false;
}
