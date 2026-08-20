<?php

declare(strict_types=1);

namespace TotpMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Config\TotpMfa as TotpMfaConfig;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * The "please confirm it's you" page shown by the RequireFreshTotp
 * filter when a protected route is reached without a recent-enough
 * step-up verification.
 *
 * Distinct from TotpMfa (the login action): this operates on an
 * already-fully-logged-in user (auth()->user()), not Shield's
 * "pending login" user - there is no Shield ActionInterface machinery
 * involved here at all, just an ordinary controller behind an
 * ordinary filter.
 */
class TotpStepUpController extends Controller
{
    protected TotpIdentityStore $store;
    protected TotpMfaConfig $config;

    public function __construct()
    {
        $this->store  = new TotpIdentityStore();
        $this->config = config('TotpMfa');
    }

    public function show(): string
    {
        return view($this->config->views['totp_step_up']);
    }

    public function verify(): RedirectResponse
    {
        $user = auth()->user();
        $code = trim((string) $this->request->getPost('code'));

        if ($code === '' || ! $this->store->verifyLoginCode($user, $code)) {
            return redirect()->back()->with('error', lang('TotpMfa.invalidCode'));
        }

        session()->set($this->config->stepUpSessionKey, time());

        $redirectTo = session('totp_step_up_redirect');
        session()->remove('totp_step_up_redirect');

        // $redirectTo, when present, was captured by RequireFreshTotp
        // from current_url() on this same site - not user-supplied
        // input - so this isn't an open-redirect risk. Falls back to
        // the app's normal post-login destination if it's somehow
        // missing (e.g. this page was reached directly rather than
        // via the filter).
        return redirect()->to($redirectTo ?: config('Auth')->loginRedirect());
    }
}
