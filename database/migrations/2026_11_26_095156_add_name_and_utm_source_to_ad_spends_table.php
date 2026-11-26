<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_spends', function (Blueprint $table): void {
            $table->string('name', 60)->nullable()->after('campaign_id');
            $table->string('utm_source')->nullable()->after('name');
        });
    }
};
