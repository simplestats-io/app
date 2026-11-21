<?php

use App\Services\ReferrerSpamService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

function spamListBody(int $count): string
{
    return collect(range(1, $count))->map(fn (int $i): string => "spam-$i.com")->implode("\n");
}

it('stores the downloaded list and the service uses it over the bundled one', function (): void {
    Http::fake([ReferrerSpamService::SOURCE_URL => Http::response(spamListBody(1000)."\nFRESH-Spam.xyz\n")]);

    $this->artisan('app:update-referrer-spam-list')->assertSuccessful();

    $service = new ReferrerSpamService;

    expect(Storage::disk('local')->exists(ReferrerSpamService::STORAGE_PATH))->toBeTrue()
        ->and($service->isSpam('https://fresh-spam.xyz/'))->toBeTrue()
        ->and($service->isSpam('aocr.org'))->toBeFalse();
});

it('keeps the current list when the download looks incomplete', function (): void {
    Storage::disk('local')->put(ReferrerSpamService::STORAGE_PATH, "existing-spam.com\n");
    Http::fake([ReferrerSpamService::SOURCE_URL => Http::response(spamListBody(10))]);

    $this->artisan('app:update-referrer-spam-list')->assertFailed();

    expect(Storage::disk('local')->get(ReferrerSpamService::STORAGE_PATH))->toBe("existing-spam.com\n");
});

it('keeps the current list when the download fails', function (): void {
    Storage::disk('local')->put(ReferrerSpamService::STORAGE_PATH, "existing-spam.com\n");
    Http::fake([ReferrerSpamService::SOURCE_URL => Http::response('', 500)]);

    $this->artisan('app:update-referrer-spam-list')->assertFailed();

    expect(Storage::disk('local')->get(ReferrerSpamService::STORAGE_PATH))->toBe("existing-spam.com\n");
});

it('falls back to the bundled list', function (): void {
    $service = new ReferrerSpamService;

    expect($service->isSpam('aocr.org'))->toBeTrue()
        ->and($service->isSpam('https://www.AOCR.org/x'))->toBeTrue()
        ->and($service->isSpam('google.com'))->toBeFalse()
        ->and($service->isSpam(null))->toBeFalse()
        ->and($service->isSpam('google'))->toBeFalse();
});

it('falls back to the bundled list when the stored one is too small', function (): void {
    Storage::disk('local')->put(ReferrerSpamService::STORAGE_PATH, '');

    expect((new ReferrerSpamService)->isSpam('aocr.org'))->toBeTrue();
});
