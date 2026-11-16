<?php

namespace App\Models;

use App\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;

class StatsPage extends Model
{
    use HasTeamScope;

    public $timestamps = false;
}
