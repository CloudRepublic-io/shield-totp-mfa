<?php

declare(strict_types=1);

namespace TotpMfa\Libraries;

use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;

/**
 * Shared "finish the pending action" logic, used by TotpMfa (login),
 * TotpActivator (register), and TotpActivatorController (the skip
 * link).
 *
 * Confirmed against a real, working reference implementation to
 * require BOTH steps below - completeLogin() alone was not enough:
 *
 *   1. Manually clear Shield's own pending-action session markers
 *      (auth_action / auth_action_message).
 *   2. Call completeLogin($user) - the method Shield's own
 *      Session::attempt() itself calls to finish a pending action
 *      (login() is a stricter method that refuses if identities still
 *      exist for the action type).
 *
 * Skipping step 1 was a real bug here: registration's "skip for now"
 * link appeared to work (user activated, identity deleted), but the
 * very next page load bounced straight back into the pending
 * registration flow, because a stale auth_action session key was
 * still telling Shield's own per-request check the action wasn't
 * done.
 */
trait CompletesPendingAction
{
    protected function clearAuthActionSession(): void
    {
        $field = setting('Auth.sessionConfig')['field'];

        $sessionUserInfo = session($field) ?? [];
        unset($sessionUserInfo['auth_action'], $sessionUserInfo['auth_action_message']);
        session()->set($field, $sessionUserInfo);
    }

    protected function completePendingAction(User $user): void
    {
        $this->clearAuthActionSession();

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $authenticator->completeLogin($user);
    }
}
