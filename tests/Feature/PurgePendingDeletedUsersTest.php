<?php

use App\Models\User;
use Laravel\Jetstream\Contracts\DeletesUsers;
use Spatie\DeletedModels\Models\DeletedModel;

it('hard-deletes users marked longer than the retention window ago', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'deleted_at' => now()->subMonths(3),
    ]);
    $personalTeam = $user->personalTeam();

    $this->artisan('app:purge-pending-deleted-users');

    expect($user->fresh())->toBeNull();
    expect($personalTeam->fresh())->toBeNull();
});

it('keeps users marked within the retention window', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'deleted_at' => now()->subMonth(),
    ]);

    $this->artisan('app:purge-pending-deleted-users');

    expect($user->fresh())->not->toBeNull();
});

it('ignores users that are not marked for deletion', function (): void {
    $user = User::factory()->withPersonalTeam()->create(['deleted_at' => null]);

    $this->artisan('app:purge-pending-deleted-users');

    expect($user->fresh())->not->toBeNull();
});

it('archives users purged for inactivity with name, email and signup date only', function (): void {
    $user = User::factory()->withPersonalTeam()->create([
        'deleted_at' => now()->subMonths(3),
        'inactive_since' => now()->subMonths(4),
    ]);

    $this->artisan('app:purge-pending-deleted-users');

    $archived = DeletedModel::where('model', (new User)->getMorphClass())->where('key', $user->id)->sole();

    expect(array_keys($archived->values))->toEqualCanonicalizing(['name', 'email', 'created_at'])
        ->and($archived->values['email'])->toBe($user->email);
});

it('does not archive users who asked for their deletion', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    resolve(DeletesUsers::class)->delete($user);

    expect(User::withTrashed()->find($user->id))->toBeNull()
        ->and(DeletedModel::where('key', $user->id)->exists())->toBeFalse();
});

it('prunes archived users after the configured retention', function (): void {
    $old = DeletedModel::create(['key' => '1', 'model' => (new User)->getMorphClass(), 'values' => ['email' => 'old@example.com']]);
    $old->forceFill(['created_at' => now()->subDays(400)])->save();
    $recent = DeletedModel::create(['key' => '2', 'model' => (new User)->getMorphClass(), 'values' => ['email' => 'new@example.com']]);

    $this->artisan('model:prune', ['--model' => [DeletedModel::class]])->assertSuccessful();

    expect(DeletedModel::find($old->id))->toBeNull()
        ->and(DeletedModel::find($recent->id))->not->toBeNull();
});

it('does not archive purged users that were deleted on request', function (): void {
    $user = User::factory()->withPersonalTeam()->create(['deleted_at' => now()->subMonths(3), 'inactive_since' => null]);

    $this->artisan('app:purge-pending-deleted-users');

    expect(User::withTrashed()->find($user->id))->toBeNull()
        ->and(DeletedModel::where('key', $user->id)->exists())->toBeFalse();
});

it('removes archived users deleted on request and scrubs the inactive ones', function (): void {
    $morph = (new User)->getMorphClass();
    $requested = DeletedModel::create(['key' => '1', 'model' => $morph, 'values' => ['email' => 'a@example.com', 'inactive_since' => null, 'password' => 'hash']]);
    $inactive = DeletedModel::create(['key' => '2', 'model' => $morph, 'values' => ['name' => 'B', 'email' => 'b@example.com', 'created_at' => '2025-01-01T00:00:00.000000Z', 'inactive_since' => '2025-06-01', 'password' => 'hash', 'two_factor_secret' => 'secret']]);

    (require database_path('migrations/2026_11_25_111636_scrub_deleted_user_archive.php'))->up();

    expect(DeletedModel::find($requested->id))->toBeNull()
        ->and($inactive->fresh()->values)->toBe(['name' => 'B', 'email' => 'b@example.com', 'created_at' => '2025-01-01T00:00:00.000000Z']);
});
