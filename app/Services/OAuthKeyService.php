<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Passport;
use phpseclib3\Crypt\RSA;

/**
 * Passport signs and verifies OAuth tokens with an RSA key pair. Instead of making
 * every installation run passport:keys, the pair is created on first use in the
 * storage folder (the persistent Docker volume, the shared folder on the SaaS).
 * PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY take precedence, e.g. for several
 * web servers that do not share their storage.
 */
class OAuthKeyService
{
    private const int KEY_LENGTH = 4096;

    public function ensureKeys(): void
    {
        if (filled(config('passport.private_key')) && filled(config('passport.public_key'))) {
            return;
        }

        $publicKey = Passport::keyPath('oauth-public.key');
        $privateKey = Passport::keyPath('oauth-private.key');

        if ($this->keyPairExists($publicKey, $privateKey)) {
            return;
        }

        // Several Octane workers or PHP-FPM processes may get here at once; the lock
        // makes sure only one pair is written and every process reads that one.
        Cache::lock('oauth-key-pair', 60)->block(30, function () use ($publicKey, $privateKey): void {
            if ($this->keyPairExists($publicKey, $privateKey)) {
                return;
            }

            $key = RSA::createKey(self::KEY_LENGTH);

            $this->writeAtomically($publicKey, (string) $key->getPublicKey(), 0660);
            $this->writeAtomically($privateKey, (string) $key, 0600);
        });
    }

    private function keyPairExists(string $publicKey, string $privateKey): bool
    {
        return file_exists($publicKey) && file_exists($privateKey);
    }

    private function writeAtomically(string $path, string $contents, int $permissions): void
    {
        $temporaryPath = $path.'.tmp';

        // Restrict the file before the private key lands in it.
        touch($temporaryPath);
        chmod($temporaryPath, $permissions);
        file_put_contents($temporaryPath, $contents);
        rename($temporaryPath, $path);
    }
}
