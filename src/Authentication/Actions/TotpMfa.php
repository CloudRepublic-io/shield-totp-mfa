<?php

declare(strict_types=1);

namespace TotpMfa\Authentication\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use Config\TotpMfa as TotpMfaConfig;
use TotpMfa\Libraries\CompletesPendingAction;
use TotpMfa\Libraries\TotpIdentityStore;
use TotpMfa\Models\RememberedDeviceModel;

/**
 * TOTP (Google/Microsoft Authenticator) login verification action for
 * Shield, with an optional "remember this device" cookie so users
 * aren't asked for a code again on the same browser until it expires
 * or they revoke it.
 *
 *   public array $actions = [
 *       'register' => \TotpMfa\Authentication\Actions\TotpActivator::class, // optional
 *       'login'    => \TotpMfa\Authentication\Actions\TotpMfa::class,
 *   ];
 *
 * VERIFICATION-ONLY BY DEFAULT - deliberately. Enrollment normally
 * happens somewhere else entirely: either via TotpActivator (the
 * 'register' action, for setup during signup), the standalone
 * TotpSettingsController, or the shield-mfa-dispatcher package's
 * settings page (for existing users opting in later). All of these
 * write to the exact same permanent identity this class reads from
 * (TotpIdentityStore::ID_TYPE_TOTP).
 *
 * Set $config->forceEnrollmentOnNextLogin = true to change this: a
 * user who reaches login without having enrolled will be shown the
 * same QR-code setup TotpActivator gives at registration, as part of
 * their login attempt, instead of being let straight through. Useful
 * for rolling out mandatory MFA to existing users, or for undoing a
 * "skip for now" at registration on their very next login. See
 * getType()/createIdentity() below for how this is implemented without
 * reintroducing the exact bug described next.
 *
 * WHY THIS MATTERS, AND WHY AN EARLIER VERSION OF THIS FILE GOT IT
 * WRONG: Shield decides whether an action is "pending" purely by
 * whether an identity of getType()'s type exists in the database -
 * not by anything about that identity's state (confirmed vs pending,
 * expired vs not). An earlier version of this class tried to handle
 * BOTH enrollment and verification from within the 'login' action
 * itself, unconditionally, with getType() always pointing at the
 * permanent secret - and since that secret is deliberately never
 * deleted, Shield's own pending-check found it on every single
 * request, forever, and the login would appear to briefly succeed
 * before bouncing straight back to the login form in an infinite loop.
 *
 * The current design still avoids that: for an already-enrolled user,
 * createIdentity() remains a genuine no-op and getType() still points
 * at the permanent, never-deleted secret - safe, because Shield only
 * ever reaches this action for such a user in the first place because
 * that identity already exists. For a not-yet-enrolled user, forcing
 * enrollment is the ONE case where getType()/createIdentity() need to
 * create and point at something - and they deliberately point at the
 * same short-lived, disposable activation type TotpActivator uses
 * (deleted the moment enrollment is confirmed), never the permanent
 * one, which is what keeps this from reintroducing the infinite loop.
 *
 * IMPORTANT: completePendingLogin() needs to match how your installed
 * Shield version finishes a pending 2FA login - confirmed against
 * v1.3.0 as `completeLogin()`, not `login()`. Worth a quick sanity
 * check if you're on a different version -
 * vendor/codeigniter4/shield/src/Authentication/Authenticators/Session.php
 */
class TotpMfa implements ActionInterface
{
    use CompletesPendingAction;

    protected TotpIdentityStore $store;
    protected RememberedDeviceModel $devices;
    protected TotpMfaConfig $config;

    public function __construct()
    {
        $this->store   = new TotpIdentityStore();
        $this->devices = model(RememberedDeviceModel::class);
        $this->config  = config('TotpMfa');
    }

    public function show(): string
    {
        $user = $this->getPendingUser();

        // --- Remembered device short-circuit -------------------------------
        // show()'s return type is contractually `string` (it's meant to
        // render a view), so it can't just `return redirect()->to(...)`.
        // Instead we send the redirect directly and stop execution -
        // the standard, if slightly blunt, way to bail out of a
        // string-typed method early.
        if ($this->config->rememberDeviceEnabled && $this->hasValidRememberedDevice($user)) {
            $this->completePendingAction($user);

            // Build the redirect response first, then attach any
            // cookie hasValidRememberedDevice() queued (a rotated
            // validator) directly onto THIS object, rather than a
            // separately-fetched service('response') instance -
            // redirect() is not guaranteed to return the same object,
            // and a cookie set on the wrong one never reaches the
            // browser. See "remember this device doesn't work" in the
            // README for the full story.
            $response = redirect()->to(config('Auth')->loginRedirect());
            $this->applyQueuedCookie($response);
            $response->send();
            exit;
        }

        if ($this->store->hasEnrolled($user)) {
            return view($this->config->views['totp_mfa_verify'], [
                'rememberDays'    => $this->config->rememberDays,
                'rememberEnabled' => $this->config->rememberDeviceEnabled,
            ]);
        }

        // Not enrolled. Whether Shield ever routes a login attempt
        // here AT ALL for such a user depends entirely on what
        // getType()/createIdentity() just did (see their doc comments)
        // - if $config->forceEnrollmentOnNextLogin is false, Shield's
        // own pending-check will have found no identity to match and
        // completed the login before ever reaching this method, making
        // the branch below purely defensive (e.g. a race condition
        // where enrollment was removed in another tab moments ago).
        if (! $this->config->forceEnrollmentOnNextLogin) {
            $this->completePendingAction($user);

            redirect()->to(config('Auth')->loginRedirect())->send();
            exit;
        }

        // Forced enrollment: show the same QR-code experience
        // TotpActivator gives at registration, just triggered by login
        // instead.
        $enrollment = $this->store->beginEnrollment($user, $user->email ?? ('user-' . $user->id));

        return view($this->config->views['totp_activator_enroll'], [
            'provisioningUri' => $enrollment['provisioningUri'],
            'manualKey'        => $enrollment['manualKey'],
        ]);
    }

    /**
     * TOTP has no "send" step, so this simply mirrors show(). It
     * exists in case Shield's ActionController expects handle() to be
     * reachable as a POST target in some flows.
     */
    public function handle(IncomingRequest $request): Response
    {
        return service('response')->setBody($this->show());
    }

    public function verify(IncomingRequest $request): Response
    {
        $user = $this->getPendingUser();
        $code = trim((string) $request->getPost('code'));

        if ($code === '') {
            return redirect()->back()->withInput()->with('error', lang('TotpMfa.enterCode'));
        }

        // Branches the same way show() does: an already-enrolled user
        // is verifying their existing secret; a not-yet-enrolled one
        // (only reachable here at all when $config->forceEnrollmentOnNextLogin
        // is true) is confirming the QR code they were just shown.
        $verified = $this->store->hasEnrolled($user)
            ? $this->store->verifyLoginCode($user, $code)
            : $this->store->confirmEnrollment($user, $code);

        if (! $verified) {
            return redirect()->back()->withInput()->with('error', lang('TotpMfa.invalidCode'));
        }

        $this->completePendingAction($user);
        $this->maybeRememberDevice($user, $request);

        // Same fix as show()'s remembered-device short-circuit: build
        // the response first, then attach any queued cookie directly
        // onto it, rather than a separately-fetched service('response').
        $response = redirect()->to(config('Auth')->loginRedirect())
            ->with('message', lang('TotpMfa.successMessage'));
        $this->applyQueuedCookie($response);

        return $response;
    }

    /**
     * {@inheritDoc}
     *
     * Dynamic, not a fixed constant - and that matters. For an already
     * enrolled user, this returns the permanent secret's type
     * (TotpIdentityStore::ID_TYPE_TOTP), same as always: Shield finds
     * that identity, knows a code needs verifying, and never deletes
     * it, which is what makes a permanent secret compatible with
     * Shield's pending-check in the first place (see this class's main
     * doc comment).
     *
     * For a NOT-yet-enrolled user, when
     * $config->forceEnrollmentOnNextLogin is true, this returns the
     * disposable activation type instead
     * (TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE) - the same type
     * TotpActivator uses at registration. createIdentity() (below)
     * creates that same disposable identity in this scenario, so
     * Shield's pending-check finds it and correctly treats the login
     * as requiring this action - which it otherwise never would for a
     * user with no identity of any kind at all.
     *
     * Both this method and createIdentity() independently ask "is this
     * user enrolled?" and must agree - they do, because both check the
     * database via TotpIdentityStore rather than any cached/session
     * state, and nothing changes the answer between the two calls
     * within a single request.
     */
    public function getType(): string
    {
        if (! $this->config->forceEnrollmentOnNextLogin) {
            return TotpIdentityStore::ID_TYPE_TOTP;
        }

        $user = $this->getPendingUserOrNull();

        if ($user !== null && ! $this->store->hasEnrolled($user)) {
            return TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE;
        }

        return TotpIdentityStore::ID_TYPE_TOTP;
    }

    /**
     * {@inheritDoc}
     *
     * A genuine no-op for an already-enrolled user (see class doc
     * comment for why that's safe). For a not-yet-enrolled user, ONLY
     * when $config->forceEnrollmentOnNextLogin is true, this creates
     * the same disposable activation identity TotpActivator would at
     * registration - matching whatever getType() (above) just decided
     * to report, so Shield's pending-check finds something and
     * actually routes this login attempt to show()/verify() instead of
     * completing it immediately.
     */
    public function createIdentity(User $user): string
    {
        if ($this->config->forceEnrollmentOnNextLogin && ! $this->store->hasEnrolled($user)) {
            $enrollment = $this->store->beginEnrollment($user, $user->email ?? ('user-' . $user->id));

            return $enrollment['secret'];
        }

        return '';
    }

    // -------------------------------------------------------------------
    // Remember-this-device
    // -------------------------------------------------------------------

    protected function hasValidRememberedDevice(User $user): bool
    {
        $cookie = $this->request()->getCookie($this->config->rememberCookieName);

        if (! is_string($cookie) || ! str_contains($cookie, ':')) {
            return false;
        }

        [$selector, $validator] = explode(':', $cookie, 2);

        $device = $this->devices->findActiveBySelector($selector);

        if ($device === null || (int) $device['user_id'] !== $user->id) {
            $this->forgetDeviceCookie();

            return false;
        }

        if (! hash_equals($device['hashed_validator'], hash('sha256', $validator))) {
            // Validator mismatch on an otherwise-known selector is a red
            // flag (stolen/duplicated cookie) - revoke the device
            // entirely rather than silently falling back to MFA.
            $this->devices->delete($device['id']);
            $this->forgetDeviceCookie();

            return false;
        }

        // Rotate the validator on every successful use so a
        // previously-sniffed cookie value stops working. Queued here
        // rather than set immediately - see queueDeviceCookie()'s doc
        // comment for why.
        $newValidator = bin2hex(random_bytes(32));
        $this->devices->touch((int) $device['id'], hash('sha256', $newValidator));
        $this->queueDeviceCookie($selector, $newValidator);

        return true;
    }

    protected function maybeRememberDevice(User $user, IncomingRequest $request): void
    {
        if (! $this->config->rememberDeviceEnabled) {
            return;
        }

        if ($request->getPost('remember_device') === null) {
            return;
        }

        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));

        $deviceName = trim((string) $request->getPost('device_name'));

        if ($deviceName === '') {
            $deviceName = $this->guessDeviceName((string) $request->getUserAgent());
        }

        $this->devices->insert([
            'user_id'          => $user->id,
            'selector'         => $selector,
            'hashed_validator' => hash('sha256', $validator),
            'device_name'      => $deviceName,
            'ip_address'       => $request->getIPAddress(),
            'user_agent'       => substr((string) $request->getUserAgent(), 0, 255),
            'last_used_at'     => date('Y-m-d H:i:s'),
            'expires_at'       => date('Y-m-d H:i:s', time() + $this->config->rememberDays * DAY),
        ]);

        $this->queueDeviceCookie($selector, $validator);
    }

    /**
     * Not set immediately - queued and only actually attached (via
     * applyQueuedCookie()) directly onto whatever response object is
     * about to be sent. This matters specifically because both call
     * sites (show()'s remembered-device short-circuit, and verify()'s
     * success path) build a redirect() response - and redirect() is
     * not guaranteed to return the same object as service('response').
     * A cookie set on the wrong one is silently dropped: it never
     * reaches the browser, even though everything else (the database
     * row, the redirect itself) works fine. That mismatch was the
     * actual cause of "remember this device" appearing to do nothing.
     */
    protected ?array $queuedCookie = null;

    protected function queueDeviceCookie(string $selector, string $validator): void
    {
        $this->queuedCookie = ['selector' => $selector, 'validator' => $validator];
    }

    protected function applyQueuedCookie(Response $response): void
    {
        if ($this->queuedCookie === null) {
            return;
        }

        $response->setCookie([
            'name'     => $this->config->rememberCookieName,
            'value'    => $this->queuedCookie['selector'] . ':' . $this->queuedCookie['validator'],
            'expire'   => $this->config->rememberDays * DAY,
            'path'     => '/',
            'secure'   => $this->config->cookieSecure,
            'httponly' => true,
            'samesite' => $this->config->cookieSameSite,
        ]);

        $this->queuedCookie = null;
    }

    /**
     * Deleting a cookie, unlike setting one, is safe to do directly on
     * service('response') here: both call sites that use this
     * (hasValidRememberedDevice()'s "invalid/stolen cookie" branches)
     * end in show() returning a plain string - no redirect() involved,
     * so service('response') genuinely is the object actually sent.
     */
    protected function forgetDeviceCookie(): void
    {
        service('response')->deleteCookie($this->config->rememberCookieName);
    }

    /**
     * A deliberately simple, dependency-free User-Agent guess - good
     * enough as a default label; the user can rename devices from the
     * "remembered devices" management page.
     */
    protected function guessDeviceName(string $userAgent): string
    {
        $os = match (true) {
            (bool) preg_match('/windows/i', $userAgent)              => 'Windows',
            (bool) preg_match('/iphone/i', $userAgent)                => 'iPhone',
            (bool) preg_match('/ipad/i', $userAgent)                  => 'iPad',
            (bool) preg_match('/macintosh|mac os/i', $userAgent)      => 'Mac',
            (bool) preg_match('/android/i', $userAgent)               => 'Android',
            (bool) preg_match('/linux/i', $userAgent)                 => 'Linux',
            default                                                    => 'Unknown device',
        };

        $browser = match (true) {
            (bool) preg_match('/edg\//i', $userAgent)     => 'Edge',
            (bool) preg_match('/chrome/i', $userAgent)    => 'Chrome',
            (bool) preg_match('/firefox/i', $userAgent)   => 'Firefox',
            (bool) preg_match('/safari/i', $userAgent)    => 'Safari',
            default                                        => '',
        };

        return trim($browser . ' on ' . $os);
    }

    // -------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------

    protected function getPendingUser(): User
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null) {
            throw new RuntimeException('TotpMfa: cannot get the pending login user.');
        }

        return $user;
    }

    /**
     * Used only by getType(), which - unlike every other method here -
     * can legitimately be called by Shield's own internals in contexts
     * where there might not be a pending user yet. Returns null rather
     * than throwing in that case, so getType() can fall back to the
     * default type instead of blowing up Shield's own pending-action
     * check.
     */
    protected function getPendingUserOrNull(): ?User
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        return $authenticator->getPendingUser();
    }

    /**
     * CONFIRMED against Shield v1.3.0's actual Session::attempt():
     * `login()` is a stricter, separate public method that refuses if
     * it finds leftover identities for the pending action type.
     * completeLogin() is the method Shield's own attempt() calls to
     * finish a pending action. See TotpMfa\Libraries\CompletesPendingAction
     * (used via the trait below) for the full completion sequence -
     * clearing session markers matters too, not just this call.
     */

    protected function request(): IncomingRequest
    {
        /** @var IncomingRequest $request */
        $request = service('request');

        return $request;
    }
}
