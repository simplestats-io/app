<?php

namespace Database\Factories;

use App\Models\GscConnection;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GscConnection>
 */
class GscConnectionFactory extends Factory
{
    protected $model = GscConnection::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'team_id' => fn (array $attributes): int => Project::withoutGlobalScopes()->findOrFail($attributes['project_id'])->team_id,
            'google_email' => fake()->safeEmail(),
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->addHour(),
            'site_url' => 'sc-domain:example.com',
        ];
    }
}
