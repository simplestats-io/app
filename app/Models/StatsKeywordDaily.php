<?php

namespace App\Models;

use App\Concerns\HasProjectAccessScope;
use App\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Model;

class StatsKeywordDaily extends Model
{
    use HasProjectAccessScope;
    use HasTeamScope;

    protected $table = 'stats_keyword_daily';

    public $timestamps = false;

    protected $guarded = [];
}
