<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Copy this file to app/Config/TotpMfa.php in the host application.
 */
class TotpMfa extends BaseConfig
{
    // -- TOTP parameters ----------------------------------------------------
    // The defaults below are what Google/Microsoft Authenticator, Authy,
    // etc. all assume. Don't change digits/period/algorithm unless you
    // know every authenticator app your users might use supports it.

    /** Name shown above the code inside the authenticator app. */
    public string $issuer = 'My App';

    public int $digits = 6;
    public int $period = 30; // seconds per code
    public string $algorithm = 'sha1';

    /** Allowed clock-drift window, in steps of $period seconds either side. */
    public int $window = 1;

    /** Secret length in bytes (20 = 160-bit, the de-facto standard). */
    public int $secretBytes = 20;

    /**
     * How long a not-yet-confirmed activation (QR code shown, no code
     * entered yet) stays valid, in seconds, before a fresh secret/QR
     * is issued on the next attempt. Stored as
     * TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE's `expires` column -
     * unrelated to a confirmed, enrolled secret, which never expires.
     */
    public int $enrollmentWindow = 900; // 15 minutes

    /**
     * If true, a user who reaches login without having enrolled a
     * TOTP secret is required to set one up right then - shown the QR
     * code as part of their login attempt, instead of being let
     * through. Useful for rolling out mandatory MFA to users who
     * signed up before it was required, or who used TotpActivator's
     * "skip for now" link at registration.
     *
     * Note the interaction with that skip link: if this is true, a
     * user who skips at registration will simply be prompted again on
     * their very next login - skipping only defers setup by one
     * login, not indefinitely. If you want a genuine long-term skip
     * alongside this setting, you'd need your own exemption mechanism
     * (e.g. a grace-period flag on the user) - not something this
     * package tries to guess at for you.
     */
    public bool $forceEnrollmentOnNextLogin = false;

    // -- Remember this device ------------------------------------------------

    public bool $rememberDeviceEnabled = true;

    /** How long a remembered device skips MFA for, in days. */
    public int $rememberDays = 30;

    public string $rememberCookieName = 'remember_totp_device';

    /**
     * Set false only for local HTTP development. Must be true in
     * production - this cookie grants an MFA-free login.
     */
    public bool $cookieSecure = true;

    public string $cookieSameSite = 'Lax';

    // -- Step-up auth for sensitive pages (RequireFreshTotp filter) ---------

    /**
     * How long, in seconds, a step-up TOTP challenge stays "fresh"
     * before a protected page requires the user to enter a code
     * again. Independent of the login-time "remember this device"
     * cookie above - that skips ordinary login MFA entirely; this is
     * about re-confirming identity immediately before a sensitive
     * action, even within an already-logged-in session.
     */
    public int $stepUpFreshnessSeconds = 900; // 15 minutes

    /** Session key used to record when the user last passed a step-up challenge. */
    public string $stepUpSessionKey = 'totp_step_up_verified_at';

    /**
     * If true, a user with no TOTP secret at all is redirected to
     * enroll before a step-up-protected page is reached, rather than
     * being let through with nothing to challenge them against.
     */
    public bool $stepUpRequiresEnrollment = false;

    /**
     * Route name to send an unenrolled user to when
     * $stepUpRequiresEnrollment is true. Defaults to the standalone
     * TotpSettingsController's enrollment route - change this to
     * 'mfa-settings-totp-enroll' if you're using the
     * shield-mfa-dispatcher package's settings page instead.
     */
    public string $stepUpEnrollRouteName = 'totp-settings-enroll';

    // -- Views ----------------------------------------------------------------

    /**
     * View paths used by this package, keyed by a logical name -
     * exactly the same pattern Shield itself uses for
     * Config\Auth::$views. Override any of these in your own copy of
     * this file to point at your own view files instead (they don't
     * need to live under the TotpMfa\Views namespace at all - any
     * view path/name your app's view() call can resolve works). You
     * only need to list the ones you're overriding; anything left out
     * falls back to the default listed here.
     *
     * Whatever view you substitute in must accept the same variables
     * the default expects - check the corresponding file under
     * src/Views/ for exactly what each one is passed.
     */
    public array $views = [
        'totp_mfa_verify'          => 'TotpMfa\Views\totp_mfa_verify',
        'totp_activator_enroll'    => 'TotpMfa\Views\totp_activator_enroll',
        'totp_settings_index'      => 'TotpMfa\Views\totp_settings_index',
        'totp_settings_enroll'     => 'TotpMfa\Views\totp_settings_enroll',
        'totp_step_up'             => 'TotpMfa\Views\totp_step_up',
        'remembered_devices_index' => 'TotpMfa\Views\remembered_devices_index',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->issuer = env('totpMfa.issuer', $this->issuer);
    }
}
