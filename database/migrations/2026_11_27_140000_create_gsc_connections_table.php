<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Google Search Console connection per project. The tokens belong to the
     * Google account that connected it, which need not be the SimpleStats user's.
     */
    public function up(): void
    {
        Schema::create('gsc_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('google_email')->nullable();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('site_url')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->date('last_synced_until_date')->nullable();
            $table->timestamp('reconnect_required_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'site_url']);
        });
    }
};
