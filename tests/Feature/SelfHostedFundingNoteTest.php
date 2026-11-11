<?php

it('shows the cloud funding note in self-hosted mails', function (string $appType, bool $visible): void {
    config(['app.app_type' => $appType]);

    $html = view('emails.partials.self-hosted-funding')->render();

    expect(str_contains($html, 'utm_source=self-hosted'))->toBe($visible);
})->with([
    'self-hosted' => ['default', true],
    'saas' => ['saas', false],
]);
