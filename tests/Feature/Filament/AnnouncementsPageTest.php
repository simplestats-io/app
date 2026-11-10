<?php

use App\Filament\Pages\Announcements;
use App\Jobs\SendAnnouncementToUser;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\DeletedModels\Models\DeletedModel;

beforeEach(function (): void {
    $this->skipUnlessSaas();

    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($this->admin);
});

test('send announcement page loads for admin users', function (): void {
    Livewire::test(Announcements::class)
        ->assertOk();
});

test('send announcement page requires subject and body', function (): void {
    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => '',
            'body' => '',
        ])
        ->call('send')
        ->assertHasFormErrors(['subject' => 'required', 'body' => 'required']);
});

test('send announcement dispatches jobs for verified users', function (): void {
    Bus::fake();

    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Test Announcement',
            'body' => 'This is a test announcement.',
        ])
        ->call('send')
        ->assertNotified();

    Bus::assertDispatched(SendAnnouncementToUser::class, fn ($job): bool => $job->recipientEmail === $this->admin->email
        && $job->recipientName === $this->admin->name
        && $job->subject === 'Test Announcement'
        && $job->markdownContent === 'This is a test announcement.');
});

test('send announcement includes deleted users when toggle is enabled', function (): void {
    Bus::fake();

    DeletedModel::create([
        'key' => '999',
        'model' => (new User)->getMorphClass(),
        'values' => ['name' => 'Deleted User', 'email' => 'deleted@example.com'],
    ]);

    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Test',
            'body' => 'Content',
            'include_deleted_users' => true,
        ])
        ->call('send')
        ->assertNotified();

    Bus::assertDispatched(SendAnnouncementToUser::class, fn ($job): bool => $job->recipientEmail === 'deleted@example.com'
        && $job->recipientName === 'Deleted User');
});

test('send announcement skips deleted users whose email matches an active user', function (): void {
    Bus::fake();

    $activeUser = User::factory()->create(['email_verified_at' => now()]);

    DeletedModel::create([
        'key' => '888',
        'model' => (new User)->getMorphClass(),
        'values' => ['name' => 'Old Account', 'email' => $activeUser->email],
    ]);

    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Test',
            'body' => 'Content',
            'include_deleted_users' => true,
        ])
        ->call('send')
        ->assertNotified();

    $dispatched = collect();
    Bus::assertDispatched(SendAnnouncementToUser::class, function ($job) use ($dispatched): true {
        $dispatched->push($job->recipientEmail);

        return true;
    });

    expect($dispatched->filter(fn ($email): bool => $email === $activeUser->email)->count())->toBe(1);
});

test('send announcement with admins_only toggle only dispatches to admin users', function (): void {
    Bus::fake();

    $regularUser = User::factory()->create(['email_verified_at' => now(), 'is_admin' => false]);

    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Admin Preview',
            'body' => 'Testing admin-only send.',
            'admins_only' => true,
        ])
        ->call('send')
        ->assertNotified();

    $dispatched = collect();
    Bus::assertDispatched(SendAnnouncementToUser::class, function ($job) use ($dispatched): true {
        $dispatched->push($job->recipientEmail);

        return true;
    });

    expect($dispatched)->toContain($this->admin->email);
    expect($dispatched)->not->toContain($regularUser->email);
});

test('send announcement dispatches only to custom emails when provided', function (): void {
    Bus::fake();

    $regularUser = User::factory()->create(['email_verified_at' => now(), 'is_admin' => false]);

    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Custom Blast',
            'body' => 'Hello there.',
            'custom_emails' => ['first@example.com', 'Second@Example.com', 'first@example.com'],
        ])
        ->call('send')
        ->assertNotified();

    $dispatched = collect();
    Bus::assertDispatched(SendAnnouncementToUser::class, function ($job) use ($dispatched): true {
        $dispatched->push($job->recipientEmail);

        return true;
    });

    expect($dispatched->sort()->values()->all())->toBe(['first@example.com', 'second@example.com']);
    expect($dispatched)->not->toContain($this->admin->email);
    expect($dispatched)->not->toContain($regularUser->email);
});

test('send announcement validates custom email addresses', function (): void {
    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Test',
            'body' => 'Content',
            'custom_emails' => ['not-an-email'],
        ])
        ->call('send')
        ->assertHasFormErrors(['custom_emails.0']);
});

test('announcement email greets with there when recipient name is empty', function (): void {
    $rendered = (new AnnouncementMail('', 'Subject', 'Body'))->render();

    expect($rendered)->toContain('Hey there,');
});

test('announcement email greets with the recipient name when present', function (): void {
    $rendered = (new AnnouncementMail('Jane', 'Subject', 'Body'))->render();

    expect($rendered)->toContain('Hey Jane,');
});

test('sending an announcement persists it to history', function (): void {
    Bus::fake();

    Livewire::test(Announcements::class)
        ->fillForm(['subject' => 'Release notes', 'body' => 'Content v1'])
        ->call('send');

    expect(Announcement::count())->toBe(1);

    $announcement = Announcement::first();

    expect($announcement->subject)->toBe('Release notes')
        ->and($announcement->body)->toBe('Content v1')
        ->and($announcement->last_sent_at)->not->toBeNull();
});

test('re-sending with an identical subject updates the existing announcement', function (): void {
    Bus::fake();

    $component = Livewire::test(Announcements::class);
    $component->fillForm(['subject' => 'Release notes', 'body' => 'v1'])->call('send');
    $component->fillForm(['subject' => 'Release notes', 'body' => 'v2'])->call('send');

    expect(Announcement::count())->toBe(1)
        ->and(Announcement::first()->body)->toBe('v2');
});

test('sending with a different subject creates a new announcement', function (): void {
    Bus::fake();

    $component = Livewire::test(Announcements::class);
    $component->fillForm(['subject' => 'First', 'body' => 'v1'])->call('send');
    $component->fillForm(['subject' => 'Second', 'body' => 'v1'])->call('send');

    expect(Announcement::count())->toBe(2);
});

test('loading an announcement fills the form and re-sending updates it even with a changed subject', function (): void {
    Bus::fake();

    $announcement = Announcement::factory()->create(['subject' => 'Old title', 'body' => 'Old body']);

    Livewire::test(Announcements::class)
        ->callAction(TestAction::make('load')->table($announcement))
        ->assertSchemaStateSet(['subject' => 'Old title', 'body' => 'Old body'])
        ->fillForm(['subject' => 'New title'])
        ->call('send');

    expect(Announcement::count())->toBe(1)
        ->and($announcement->refresh()->subject)->toBe('New title');
});

test('an announcement can be deleted from history', function (): void {
    $announcement = Announcement::factory()->create();

    Livewire::test(Announcements::class)
        ->callAction(TestAction::make('delete')->table($announcement));

    expect(Announcement::count())->toBe(0);
});

test('the first markdown header auto-fills an empty subject', function (): void {
    Livewire::test(Announcements::class)
        ->fillForm(['body' => "# What's new in July\n\nSome content"])
        ->assertSchemaStateSet(['subject' => "What's new in July"]);
});

test('the markdown header does not overwrite a manually entered subject', function (): void {
    Livewire::test(Announcements::class)
        ->fillForm(['subject' => 'My subject'])
        ->fillForm(['body' => '# Other header'])
        ->assertSchemaStateSet(['subject' => 'My subject']);
});

test('non-admin users cannot access the send announcement page', function (): void {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('/admin/announcements')
        ->assertForbidden();
});

test('an announcement with a send time is scheduled instead of sent', function (): void {
    Bus::fake();

    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Black Week',
            'body' => 'Half price.',
            'admins_only' => true,
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i'),
        ])
        ->call('send')
        ->assertHasNoFormErrors()
        ->assertNotified('Announcement scheduled');

    Bus::assertNotDispatched(SendAnnouncementToUser::class);

    $announcement = Announcement::sole();

    expect($announcement->scheduled_at)->not->toBeNull()
        ->and($announcement->last_sent_at)->toBeNull()
        ->and($announcement->recipients['admins_only'])->toBeTrue()
        ->and($announcement->scheduledBy->is($this->admin))->toBeTrue();
});

test('a send time in the past is rejected', function (): void {
    Livewire::test(Announcements::class)
        ->fillForm([
            'subject' => 'Too late',
            'body' => 'Content',
            'scheduled_at' => now()->subHour()->format('Y-m-d H:i'),
        ])
        ->call('send')
        ->assertHasFormErrors(['scheduled_at']);
});

test('a scheduled announcement can be cancelled from history', function (): void {
    $announcement = Announcement::factory()->create(['last_sent_at' => null, 'scheduled_at' => now()->addDay()]);

    Livewire::test(Announcements::class)
        ->callAction(TestAction::make('unschedule')->table($announcement));

    expect($announcement->refresh()->scheduled_at)->toBeNull();
});

test('due scheduled announcements are sent once with their stored recipients', function (): void {
    Bus::fake();

    $regularUser = User::factory()->create(['email_verified_at' => now(), 'is_admin' => false]);

    $due = Announcement::factory()->create([
        'last_sent_at' => null,
        'scheduled_at' => now()->subMinute(),
        'recipients' => ['custom_emails' => ['first@example.com']],
    ]);
    $future = Announcement::factory()->create(['last_sent_at' => null, 'scheduled_at' => now()->addHour()]);

    $this->artisan('app:send-scheduled-announcements')->assertSuccessful();
    $this->artisan('app:send-scheduled-announcements')->assertSuccessful();

    Bus::assertDispatchedTimes(SendAnnouncementToUser::class, 1);
    Bus::assertDispatched(SendAnnouncementToUser::class, fn ($job): bool => $job->recipientEmail === 'first@example.com'
        && $job->subject === $due->subject);

    expect($due->refresh()->scheduled_at)->toBeNull()
        ->and($due->last_sent_at)->not->toBeNull()
        ->and($future->refresh()->scheduled_at)->not->toBeNull()
        ->and($future->last_sent_at)->toBeNull();
});
