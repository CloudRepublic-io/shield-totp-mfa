<?php

declare(strict_types=1);

namespace TotpMfa\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RedirectResponse;
use Config\TotpMfa as TotpMfaConfig;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * Lets an already-logged-in user turn TOTP on or off for their own
 * account, independently of anything at registration - so someone who
 * skipped setup at signup (via TotpActivator's "skip for now" link)
 * still has a way to enable it later, without needing the separate
 * shield-mfa-dispatcher package.
 *
 * If you ARE using shield-mfa-dispatcher, you likely want that
 * package's MfaSettingsController instead (it handles switching
 * between several MFA methods, not just TOTP on/off) - this one is
 * for apps that only use TOTP and want a simple enable/disable page
 * with no method-switching concept at all.
 *
 * Both ultimately go through the exact same TotpIdentityStore, so a
 * user enrolled via one is recognized correctly regardless of which
 * (if either) is installed.
 */
class TotpSettingsController extends Controller
{
    protected TotpIdentityStore $store;
    protected TotpMfaConfig $config;

    public function __construct()
    {
        $this->store  = new TotpIdentityStore();
        $this->config = config('TotpMfa');
    }

    public function index(): string
    {
        $user = auth()->user();

        return view($this->config->views['totp_settings_index'], [
            'enrolled' => $this->store->hasEnrolled($user),
        ]);
    }

    public function enroll(): string
    {
        $user = auth()->user();

        // If this user is already enrolled in TOTP, we don't want to let them enroll again so send them back to the
        // Settings page with a message that they're already enrolled. We can't perform a normal redirect here
        // as this is a controller method that returns a string, so we need to send the redirect response and exit to prevent further execution.
        // having to change the return type and breaking the interface. 
        if ($this->store->hasEnrolled($user)) {
            $response = redirect()->to(route_to('totp-settings'))->with('message', lang('TotpMfa.alreadyEnrolled'));
            $response->send();
            exit;
        }

        $enrollment = $this->store->beginEnrollment($user, $user->email ?? ('user-' . $user->id));

        return view($this->config->views['totp_settings_enroll'], [
            'provisioningUri' => $enrollment['provisioningUri'],
            'manualKey'        => $enrollment['manualKey'],
        ]);
    }

    public function confirm(): RedirectResponse
    {
        $user = auth()->user();
        $code = trim((string) $this->request->getPost('code'));

        if ($code === '' || ! $this->store->confirmEnrollment($user, $code)) {
            return redirect()->back()->with('error', lang('TotpMfa.invalidCode'));
        }

        return redirect()->route('totp-settings')->with('message', lang('TotpMfa.enabledMessage'));
    }

    public function disable(): RedirectResponse
    {
        $this->store->disable(auth()->user());

        // Cleared on the same response object being returned, not a
        // separately-fetched service('response') - see TotpMfa's
        // README section on "remember this device doesn't work" for
        // why that distinction matters. The database row is already
        // gone either way (disable() just removed it), so this is
        // tidiness for the current browser rather than a security
        // requirement - a stale cookie value can't do anything once
        // its matching row no longer exists.
        $response = redirect()->route('totp-settings')->with('message', lang('TotpMfa.disabledMessage'));
        $response->deleteCookie($this->config->rememberCookieName);

        return $response;
    }
}
