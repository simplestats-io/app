<?php

use App\Models\User;
use App\Providers\RouteServiceProvider;

test('login screen can be rendered', function (): void {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function (): void {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(RouteServiceProvider::HOME);
});

test('users cannot authenticate with invalid password', function (): void {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users marked for deletion cannot authenticate', function (): void {
    $user = User::factory()->create([
        'deleted_at' => now(),
    ]);

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('users marked for deletion learn that their account is restorable', function (): void {
    $user = User::factory()->create();
    config(['app.app_mail' => 'support@example.com']);
    $user->delete();

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))
        ->toContain('deactivated')
        ->toContain('support@example.com');
});

test('the deactivation notice is not exposed to someone without the password', function (): void {
    // Otherwise the message turns into an account enumeration oracle.
    $user = User::factory()->create();
    $user->delete();

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->not->toContain('deactivated');
});
