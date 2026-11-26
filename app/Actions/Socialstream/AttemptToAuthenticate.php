<?php

namespace App\Actions\Socialstream;

use JoelButcher\Socialstream\Actions\AttemptToAuthenticate as BaseAction;
use JoelButcher\Socialstream\Socialstream;

class AttemptToAuthenticate extends BaseAction
{
    /**
     * The vendor action hands every login to Fortify's authenticateUsing callback when one is
     * set, and ours checks email and password, which an OAuth callback never carries. So every
     * social login of an existing user failed with "These credentials do not match our records".
     * The user is already resolved from the provider account here, so log them in directly.
     *
     * @param  int|string|null  $authIdentifier
     */
    public function handle($request, $next, $authIdentifier = null)
    {
        if ($authIdentifier === null) {
            return parent::handle($request, $next, $authIdentifier);
        }

        $this->guard->loginUsingId($authIdentifier, Socialstream::hasRememberSessionFeatures());

        return $next($request);
    }
}
