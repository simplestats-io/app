<?php

use App\Models\Project;
use App\Models\User;

test('installation step passes token, domain and tracked data of the project', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $project = Project::factory()->create([
        'team_id' => $user->currentTeam->id,
        'domain' => 'shop.test',
        'usage' => Project::USAGE_VISITORS | Project::USAGE_USERS,
    ]);

    $this->actingAs($user)
        ->get(route('setup.installation.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Setup/Installation')
            ->where('apiToken', $project->tokens()->first()->token_plain_part)
            ->where('domain', 'shop.test')
            ->where('tracking', ['visitors', 'users'])
        );
});
