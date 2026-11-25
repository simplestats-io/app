<?php

use App\Filament\Resources\Users\Actions\DeletePermanentlyAction;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Jobs\DeleteUserPermanently;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->skipUnlessSaas();

    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);

    $this->user = User::factory()->withPersonalTeam()->create();
});

it('deletes a user with their teams and projects after confirming email and password', function (): void {
    $team = $this->user->ownedTeams()->first();
    $project = Project::factory()->for($team)->create();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deletePermanently')->table($this->user), [
            'email' => $this->user->email,
            'password' => 'password',
        ])
        ->assertHasNoFormErrors();

    expect(User::withTrashed()->find($this->user->id))->toBeNull()
        ->and($team->fresh())->toBeNull()
        ->and(Project::withoutGlobalScopes()->find($project->id))->toBeNull();
});

it('soft-deletes right away and queues the permanent deletion on the low queue', function (): void {
    Queue::fake();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deletePermanently')->table($this->user), [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

    expect($this->user->fresh()->trashed())->toBeTrue();
    Queue::assertPushedOn('low', DeleteUserPermanently::class);
});

it('refuses the permanent deletion with a wrong email or password', function (string $email, string $password, string $field): void {
    Queue::fake();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deletePermanently')->table($this->user), [
            'email' => $email === 'user' ? $this->user->email : $email,
            'password' => $password,
        ])
        ->assertHasFormErrors([$field]);

    expect($this->user->fresh()->trashed())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'wrong email' => ['someone@example.com', 'password', 'email'],
    'wrong password' => ['user', 'wrong-password', 'password'],
]);

it('deactivates a user without deleting their data', function (): void {
    $team = $this->user->ownedTeams()->first();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($this->user));

    expect($this->user->fresh()->trashed())->toBeTrue()
        ->and($team->fresh())->not->toBeNull();
});

it('offers no delete actions on the own account and no bulk delete', function (): void {
    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('delete')->table($this->admin))
        ->assertActionHidden(TestAction::make('deletePermanently')->table($this->admin))
        ->assertActionDoesNotExist(TestAction::make('delete')->bulk());
});

it('keeps a user that was restored while the deletion job waited', function (): void {
    $this->user->delete();
    $job = new DeleteUserPermanently($this->user);
    $this->user->restore();

    dispatch_sync($job);

    expect($this->user->fresh())->not->toBeNull();
});

it('offers no deactivation for users with a running subscription', function (): void {
    $this->user->subscriptions()->create(['type' => 'default', 'paddle_id' => 'sub_test', 'status' => 'active']);

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('delete')->table($this->user))
        ->assertActionVisible(TestAction::make('deletePermanently')->table($this->user));
});

it('warns when owned teams have other members', function (): void {
    $this->user->ownedTeams()->first()->users()->attach(User::factory()->create(), ['role' => 'editor']);

    expect(DeletePermanentlyAction::ownedTeamsWarning($this->user))->toContain('1 other member(s)')
        ->and(DeletePermanentlyAction::ownedTeamsWarning($this->admin))->toBe('');
});
