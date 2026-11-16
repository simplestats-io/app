<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // CREATE INDEX CONCURRENTLY cannot run inside a transaction
    public $withinTransaction = false;

    /**
     * No foreign key on purpose: validating it would scan the whole (huge) table under lock.
     * The partial index stays tiny, since live-tracked visitors never carry an import id,
     * and is built concurrently so ingest keeps writing meanwhile.
     */
    public function up(): void
    {
        Schema::table('stats_visitors', function (Blueprint $table): void {
            $table->unsignedBigInteger('data_import_id')->nullable();
        });

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS stats_visitors_data_import_id_index ON stats_visitors (data_import_id) WHERE data_import_id IS NOT NULL');
    }
};
