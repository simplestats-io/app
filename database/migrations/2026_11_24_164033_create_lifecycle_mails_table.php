<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_mails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('mail', 64);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['team_id', 'mail']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('lifecycle_mails_opted_out_at')->nullable();
        });

        // The old flag meant "no more onboarding reminders" without recording which
        // one went out, so both count as sent.
        Team::query()
            ->whereNotNull('installation_reminder_sent_at')
            ->lazyById()
            ->each(function (Team $team): void {
                foreach (['setup_reminder', 'installation_reminder'] as $mail) {
                    $team->lifecycleMails()->create([
                        'mail' => $mail,
                        'sent_at' => $team->getRawOriginal('installation_reminder_sent_at'),
                    ]);
                }
            });

        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('installation_reminder_sent_at');
        });
    }
};
