<?php

use App\Enums\FeedbackType;
use App\Mail\FeedbackMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    config(['app.admin_mail' => 'admin@example.com']);
});

test('guests cannot submit feedback', function (): void {
    $response = $this->post('/feedback', [
        'type' => 'idea',
        'message' => 'A great idea',
    ]);

    $response->assertRedirect('/login');
});

test('feedback can be submitted', function (): void {
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post('/feedback', [
        'type' => 'idea',
        'message' => 'It would be great to have dark mode charts.',
    ]);

    $response->assertRedirect();
    $response->assertSessionMissing('errors');

    Mail::assertSent(FeedbackMail::class, fn (FeedbackMail $mail): bool => $mail->type === FeedbackType::IDEA
        && $mail->feedbackMessage === 'It would be great to have dark mode charts.'
        && $mail->user->is($user));
});

test('validation rejects missing fields', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post('/feedback', []);

    $response->assertSessionHasErrors(['type', 'message']);
});

test('validation rejects invalid type', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->post('/feedback', [
        'type' => 'complaint',
        'message' => 'Some message',
    ]);

    $response->assertSessionHasErrors(['type']);
});

test('feedback is unavailable without an admin mail address', function (): void {
    Mail::fake();
    config(['app.admin_mail' => null]);

    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user)->post('/feedback', [
        'type' => 'idea',
        'message' => 'Nobody would read this.',
    ])->assertForbidden();

    Mail::assertNothingSent();
});
