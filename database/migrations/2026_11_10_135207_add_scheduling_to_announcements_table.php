<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->jsonb('recipients')->nullable();
            $table->foreignId('scheduled_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
};
