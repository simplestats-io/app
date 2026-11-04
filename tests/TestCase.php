<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Paddle\Cashier;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        Http::preventStrayRequests();

        // The app tracks itself with its own client package, so every factory
        // user would otherwise send a real request to the configured API.
        Http::fake([
            Str::finish(config('simplestats-client.api_url'), '/').'*' => Http::response(),
        ]);

        // Spark's Billable trait creates a Paddle customer on every user
        // insert. Cashier::fake() only fakes events, not HTTP, so the customer
        // lookup/creation is stubbed here, otherwise every factory user hits the
        // real Paddle sandbox API (slow, and it rate-limits parallel runs).
        // The id must be unique, Cashier refuses a paddle_id that already exists.
        // Spark is saas-only, so the self-hosted build has no Cashier.
        if (class_exists(Cashier::class)) {
            Cashier::fake();

            Http::fake([
                Cashier::apiUrl().'/customers*' => fn (Request $request) => $request->method() === 'GET'
                    ? Http::response(['data' => []])
                    : Http::response(['data' => [
                        'id' => 'ctm_'.Str::lower(Str::random(26)),
                        'name' => $request['name'] ?? null,
                        'email' => $request['email'],
                    ]]),
                Cashier::apiUrl().'/pricing-preview' => Http::response(['data' => ['details' => ['line_items' => []]]]),
            ]);
        }
    }

    /**
     * CI runs the self-hosted build (composer.json), which lacks the saas-only
     * packages and code paths (Spark, Filament, blog, landing pages, ...).
     */
    public function skipUnlessSaas(): void
    {
        if (config('app.app_type') !== 'saas') {
            $this->markTestSkipped('Requires the saas build.');
        }
    }
}
