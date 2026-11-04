<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

return new class extends Migration
{
    /**
     * The command commits per team. Wrapping it in one migration transaction would
     * hold locks on the stats tables for the whole run.
     */
    public $withinTransaction = false;

    /**
     * Country names used to come from whichever geo provider answered, so the
     * same country ended up in several rows ("Netherlands" / "The Netherlands").
     */
    public function up(): void
    {
        Artisan::call('app:unify-locations');
    }
};
