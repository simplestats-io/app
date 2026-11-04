<?php

use App\Models\Project;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    actingAs($this->user);
});

test('domain is required when creating a project', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'My Project',
        'usage' => 1,
    ])->assertSessionHasErrors('domain');
});

test('domain is normalized to a bare host', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'My Project',
        'domain' => 'https://example.com/some/path?utm=foo',
        'usage' => 1,
    ])->assertSessionHasNoErrors();

    expect(Project::where('name', 'My Project')->value('domain'))->toBe('example.com');
});

test('a plain domain is accepted as-is', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Plain Domain',
        'domain' => 'example.org',
        'usage' => 1,
    ])->assertSessionHasNoErrors();

    expect(Project::where('name', 'Plain Domain')->value('domain'))->toBe('example.org');
});

test('a single label without a tld is rejected', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'No TLD',
        'domain' => 'asdfa',
        'usage' => 1,
    ])->assertSessionHasErrors('domain');
});

test('localhost is accepted as a domain', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Local Project',
        'domain' => 'http://localhost:8000',
        'usage' => 1,
    ])->assertSessionHasNoErrors();

    expect(Project::where('name', 'Local Project')->value('domain'))->toBe('localhost');
});

test('recurring tracking requires registrations and payments', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Recurring Only',
        'domain' => 'example.com',
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_SUBSCRIPTION,
    ])->assertSessionHasErrors('usage');
});

test('recurring tracking is accepted together with registrations and payments', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Full Recurring',
        'domain' => 'example.com',
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS
            | Project::USAGE_SUBSCRIPTION,
    ])->assertSessionHasNoErrors();
});

test('payments alone are rejected', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Payments Only',
        'domain' => 'example.com',
        'usage' => Project::USAGE_PAYMENTS,
    ])->assertSessionHasErrors('usage');
});

test('payments are accepted together with visitors or registrations', function (int $source): void {
    $this->post(route('projects.store'), [
        'name' => 'Linked Payments',
        'domain' => 'example.com',
        'usage' => $source | Project::USAGE_PAYMENTS,
    ])->assertSessionHasNoErrors();
})->with([
    'visitors' => Project::USAGE_VISITORS,
    'registrations' => Project::USAGE_USERS,
]);

test('the paid-only flag requires registrations and payments', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Paywalled',
        'domain' => 'example.com',
        'usage' => Project::USAGE_VISITORS,
        'flags' => Project::FLAG_PAID_ONLY,
    ])->assertSessionHasErrors('flags');
});

test('the paid-only flag is accepted and persisted with registrations and payments', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Paid Only',
        'domain' => 'example.com',
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS,
        'flags' => Project::FLAG_PAID_ONLY,
    ])->assertSessionHasNoErrors();

    expect(Project::where('name', 'Paid Only')->value('flags'))->toBe(Project::FLAG_PAID_ONLY);
});

test('flags above the supported bit count are rejected', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'Bad Flags',
        'domain' => 'example.com',
        'usage' => Project::USAGE_VISITORS
            | Project::USAGE_USERS
            | Project::USAGE_PAYMENTS,
        'flags' => Project::getFlagBitsCount() + 1,
    ])->assertSessionHasErrors('flags');
});

test('flags default to zero when omitted', function (): void {
    $this->post(route('projects.store'), [
        'name' => 'No Flags',
        'domain' => 'example.com',
        'usage' => Project::USAGE_VISITORS,
    ])->assertSessionHasNoErrors();

    expect(Project::where('name', 'No Flags')->value('flags'))->toBe(0);
});

test('hasFlags reflects the stored flags bitmask', function (): void {
    $with = Project::factory()->create(['flags' => Project::FLAG_PAID_ONLY]);
    $without = Project::factory()->create(['flags' => 0]);

    expect($with->hasFlags([Project::FLAG_PAID_ONLY]))->toBeTrue();
    expect($without->hasFlags([Project::FLAG_PAID_ONLY]))->toBeFalse();
});
