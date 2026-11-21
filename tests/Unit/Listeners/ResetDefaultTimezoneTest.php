<?php

use App\Listeners\ResetDefaultTimezone;
use Illuminate\Http\Request;
use Laravel\Octane\Events\RequestReceived;
use Tests\TestCase;

uses(TestCase::class);

afterEach(fn () => date_default_timezone_set(config('app.timezone')));

it('resets a timezone a previous request left behind in the worker', function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('Pacific/Auckland');

    (new ResetDefaultTimezone)->handle(new RequestReceived($this->app, $this->app, Request::create('/')));

    expect(date_default_timezone_get())->toBe('UTC');
});

it('runs on every Octane request', function (): void {
    expect(config('octane.listeners')[RequestReceived::class])->toContain(ResetDefaultTimezone::class);
});
