<?php

use App\Enums\StatsRangePreset;
use App\Models\CustomStatsRangePreset;
use App\Models\Project;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
});

test('user can create a custom preset', function (): void {
    $response = $this->post(route('custom-presets.store'), [
        'name' => 'Last 14 Days',
        'range_amount' => 14,
        'range_type' => 'days',
    ]);

    $response->assertRedirect();
    expect($this->user->customStatsRangePresets)->toHaveCount(1);
    expect($this->user->customStatsRangePresets->first()->name)->toBe('Last 14 Days');
});

test('user cannot exceed 10 custom presets', function (): void {
    CustomStatsRangePreset::factory()->count(10)->create(['user_id' => $this->user->id]);

    $response = $this->post(route('custom-presets.store'), [
        'name' => 'One Too Many',
        'range_amount' => 7,
        'range_type' => 'days',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('name');
});

test('user can update their own preset', function (): void {
    $preset = CustomStatsRangePreset::factory()->create(['user_id' => $this->user->id]);

    $response = $this->put(route('custom-presets.update', $preset), [
        'name' => 'Updated Name',
        'range_amount' => 30,
        'range_type' => 'months',
    ]);

    $response->assertRedirect();
    expect($preset->fresh()->name)->toBe('Updated Name');
    expect($preset->fresh()->range_amount)->toBe(30);
});

test('user cannot update another users preset', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $preset = CustomStatsRangePreset::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->put(route('custom-presets.update', $preset), [
        'name' => 'Hijacked',
        'range_amount' => 7,
        'range_type' => 'days',
    ]);

    $response->assertForbidden();
});

test('user can delete their own preset', function (): void {
    $preset = CustomStatsRangePreset::factory()->create(['user_id' => $this->user->id]);

    $response = $this->delete(route('custom-presets.destroy', $preset));

    $response->assertRedirect();
    expect(CustomStatsRangePreset::find($preset->id))->toBeNull();
});

test('user cannot delete another users preset', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $preset = CustomStatsRangePreset::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->delete(route('custom-presets.destroy', $preset));

    $response->assertForbidden();
});

test('validation requires name', function (): void {
    $response = $this->post(route('custom-presets.store'), [
        'range_amount' => 7,
        'range_type' => 'days',
    ]);

    $response->assertSessionHasErrors('name');
});

test('validation requires valid range_type', function (): void {
    $response = $this->post(route('custom-presets.store'), [
        'name' => 'Test',
        'range_amount' => 7,
        'range_type' => 'hours',
    ]);

    $response->assertSessionHasErrors('range_type');
});

test('validation requires range_amount between 1 and 60', function (): void {
    $response = $this->post(route('custom-presets.store'), [
        'name' => 'Test',
        'range_amount' => 0,
        'range_type' => 'days',
    ]);

    $response->assertSessionHasErrors('range_amount');

    $response = $this->post(route('custom-presets.store'), [
        'name' => 'Test',
        'range_amount' => 61,
        'range_type' => 'days',
    ]);

    $response->assertSessionHasErrors('range_amount');
});

test('user can update builtin preset visibility', function (): void {
    $response = $this->post(route('custom-presets.builtin-visibility'), [
        'visible' => ['today', 'last_7_days'],
    ]);

    $response->assertRedirect();
    $hiddenPresets = $this->user->fresh()->settings['hiddenPresets'];
    expect($hiddenPresets)->toContain('yesterday');
    expect($hiddenPresets)->not->toContain('today');
    expect($hiddenPresets)->not->toContain('last_7_days');
});

test('user can show all builtin presets', function (): void {
    $this->user->updateSettings('hiddenPresets', ['yesterday', 'today']);

    $allValues = array_column(StatsRangePreset::cases(), 'value');
    $response = $this->post(route('custom-presets.builtin-visibility'), [
        'visible' => $allValues,
    ]);

    $response->assertRedirect();
    expect($this->user->fresh()->settings['hiddenPresets'])->toBeEmpty();
});

test('builtin visibility rejects invalid preset', function (): void {
    $response = $this->post(route('custom-presets.builtin-visibility'), [
        'visible' => ['nonexistent'],
    ]);

    $response->assertSessionHasErrors('visible.0');
});

test('user can list their custom presets', function (): void {
    CustomStatsRangePreset::factory()->count(3)->create(['user_id' => $this->user->id]);

    $response = $this->getJson(route('custom-presets.index'));

    $response->assertOk();
    $response->assertJsonCount(3);
});

test('custom preset toOption returns correct format', function (): void {
    $preset = CustomStatsRangePreset::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Last 14 Days',
        'range_amount' => 14,
        'range_type' => 'days',
    ]);

    $option = $preset->toOption();

    expect($option['id'])->toBe('custom_'.$preset->id);
    expect($option['name'])->toBe('Last 14 Days');
    expect($option['sortWeight'])->toBe(1400);
});

test('custom preset toRange returns correct format', function (): void {
    $preset = CustomStatsRangePreset::factory()->create([
        'user_id' => $this->user->id,
        'range_amount' => 14,
        'range_type' => 'days',
    ]);

    $range = $preset->toRange();

    expect($range['range_amount'])->toBe(14);
    expect($range['range_type'])->toBe('days');
    expect($range['range_start'])->not->toBeNull();
});

test('custom rolling presets are sorted between the rolling default presets', function (): void {
    $this->user->currentTeam->update(['currency' => 'USD', 'timezone' => 'UTC']);
    Project::factory()->create(['team_id' => $this->user->currentTeam->id]);
    CustomStatsRangePreset::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Last 12 Months',
        'range_amount' => 12,
        'range_type' => 'months',
    ]);

    $this->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('presets', fn ($presets): bool => collect($presets)->pluck('id')->slice(5, 3)->values()->all() === [
            StatsRangePreset::LAST_6_MONTHS->value,
            'custom_'.$this->user->customStatsRangePresets->first()->id,
            StatsRangePreset::THIS_MONTH->value,
        ]));
});
