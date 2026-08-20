<?php

declare(strict_types=1);

namespace TotpMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use TotpMfa\Libraries\CompletesPendingAction;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * Handles the "skip for now" link on TotpActivator's enrollment view.
 *
 * This has to be a real Controller, not a method on TotpActivator
 * itself: routes are dispatched through CodeIgniter's normal
 * Controller lifecycle (which calls initController() on whatever
 * class is routed to), and TotpActivator - a Shield ActionInterface
 * implementation - doesn't extend Controller and has no such method.
 * `show()`/`handle()`/`verify()` on Action classes are only ever
 * called indirectly, by Shield's own ActionController, which is why
 * they don't hit this problem; a route pointed directly at an Action
 * class does.
 */
class TotpActivatorController extends Controller
{
    use CompletesPendingAction;

    public function skip(): RedirectResponse
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null) {
            throw new RuntimeException('TotpActivatorController: cannot get the pending registration user.');
        }

        (new TotpIdentityStore())->cancelEnrollment($user);

        // Same completion sequence as a successful verify() - see
        // CompletesPendingAction for why clearing session markers
        // matters, not just deleting the identity and activating.
        $this->completePendingAction($user);

        $authenticator->getUser()->activate();

        return redirect()->to(config('Auth')->registerRedirect());
    }
}
