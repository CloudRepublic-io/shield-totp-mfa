<?php

declare(strict_types=1);

namespace TotpMfa\Authentication\Actions;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Shield\Authentication\Actions\ActionInterface;
use CodeIgniter\Shield\Authentication\Actions\ConditionalActionInterface;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Exceptions\RuntimeException;
use TotpMfa\Libraries\CompletesPendingAction;
use TotpMfa\Libraries\TotpIdentityStore;
use Config\TotpMfa as TotpMfaConfig;

/**
 * Optional TOTP setup during registration. Register this as the
 * 'register' action if you want new users offered an authenticator-app
 * setup step as part of signup:
 *
 *   public array $actions = [
 *       'register' => \TotpMfa\Authentication\Actions\TotpActivator::class,
 *       'login'    => \TotpMfa\Authentication\Actions\TotpMfa::class,
 *   ];
 *
 * OPTIONAL BY DESIGN: the enrollment view includes a "skip for now"
 * link, handled by TotpActivatorController::skip() (see
 * routes-snippet.php for its route) rather than a method on this
 * class - routes require a real Controller, which this Action class
 * is not. A user who skips is activated without a TOTP secret, and can
 * opt in later from the self-service settings page
 * (TotpSettingsController, or shield-mfa-dispatcher's equivalent) - or
 * never, if they don't want to and
 * $config->forceEnrollmentOnNextLogin (see TotpMfa.php) is left false.
 * If that setting is true, though, skipping here only defers setup
 * until the user's very next login - TotpMfa will prompt them again
 * at that point. To make setup here mandatory instead of skippable,
 * just remove the skip link/button from the enrollment view; the
 * route being reachable doesn't force anyone through it.
 *
 * Writes to the exact same permanent identity TotpMfa (the 'login'
 * action) reads from - see TotpIdentityStore, which is shared between
 * both, plus the self-service settings page.
 *
 * ALSO REUSED, unmodified, by shield-mfa-dispatcher's forced-setup
 * flow (Config\MfaDispatcher::$requiredMethodsForGroups) - an
 * already-active, already-logged-in-once user forced to set up TOTP
 * mid-login is routed through this exact class. verify() checks
 * $user->active BEFORE calling activate()/completePendingAction() to
 * tell the two cases apart: an inactive user genuinely means fresh
 * registration (redirects to registerRedirect()); an already-active
 * one means this is the forced-setup reuse (redirects to
 * loginRedirect() instead, and skips activate() entirely - there's no
 * inactive account to activate).
 *
 * IMPLEMENTS ConditionalActionInterface - CARRIED OVER FROM
 * shield-passkey-mfa, where a confirmed, real user report showed a
 * user who had already enrolled still being routed into
 * PasskeyActivator's own enrollment flow on a later, ordinary login
 * (paired with shield-mfa-dispatcher: register=Activator,
 * login=MfaDispatcher) - despite shield-mfa-dispatcher's own
 * MfaDispatcher::resolveRequiredMethod() correctly resolving the user
 * as already enrolled. Direct log tracing there confirmed Shield
 * itself was routing straight to the register slot's activator,
 * never even reaching the login slot's action for that request.
 *
 * Confirmed against Shield's own official documentation on Auth
 * Actions: a custom action can implement ConditionalActionInterface's
 * appliesTo(User $user): bool to tell Shield directly whether it
 * should be considered pending for a given user at all - "when
 * appliesTo() returns false, Shield does not start the action and
 * ignores stored identities for that action while the condition
 * remains false." Without this, Shield apparently keeps discovering a
 * "pending" register action for a user long after they've actually
 * finished registering.
 *
 * This class's own getType() returns
 * TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE - a genuinely different
 * type from TotpMfa's own ID_TYPE_TOTP, not a shared one (an earlier,
 * now-corrected claim in shield-passkey-mfa's own README wrongly
 * assumed its two actions shared a type too - they don't either, see
 * that package's own corrected note). The most likely mechanism here:
 * ID_TYPE_TOTP_ACTIVATE is a temporary marker created during
 * registration - if it's never cleaned up once registration completes,
 * Shield could keep finding a match for the register slot's own type
 * indefinitely. This is a plausible, not fully traced, explanation -
 * see shield-passkey-mfa's own README for the same honest caveat.
 * appliesTo() below sidesteps the question of the exact mechanism
 * entirely: whatever the reason Shield might still consider this
 * pending, telling it directly not to once the user has already
 * enrolled is the documented, correct fix regardless.
 */
class TotpActivator implements ActionInterface, ConditionalActionInterface
{
    use CompletesPendingAction;

    protected TotpIdentityStore $store;
    protected TotpMfaConfig $config;

    public function __construct()
    {
        $this->store  = new TotpIdentityStore();
        $this->config = config('TotpMfa');
    }

    /**
     * {@inheritDoc}
     *
     * Confirmed via Shield's own docs: "may be called more than once
     * while Shield checks for actions, so keep it deterministic, free
     * of side effects, and fail closed when the condition cannot be
     * determined." hasEnrolled() is a plain, read-only DB check - no
     * side effects, deterministic for a given user's stored state.
     */
    public function appliesTo(User $user): bool
    {
        return ! $this->store->hasEnrolled($user);
    }

    public function show(): string
    {
        $user = $this->getPendingUser();

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

        if ($code === '' || ! $this->store->confirmEnrollment($user, $code)) {
            return redirect()->back()->withInput()->with('error', lang('TotpMfa.invalidCode'));
        }

        // Checked BEFORE completePendingAction()/activate() touch
        // anything - see the doc comment above for why this
        // distinguishes genuine registration from this same class
        // being reused as a forced-setup step during an existing
        // user's login (shield-mfa-dispatcher's
        // Config\MfaDispatcher::$requiredMethodsForGroups).
        $wasAlreadyActive = (bool) $user->active;

        // Order mirrors a confirmed-working reference implementation:
        // complete the pending action first (clears session markers +
        // completeLogin()), then re-fetch the user before activating,
        // rather than activating the earlier $user reference directly.
        $this->completePendingAction($user);

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        if (! $wasAlreadyActive) {
            $authenticator->getUser()->activate();

            return redirect()->to(config('Auth')->registerRedirect())
                ->with('message', lang('Auth.registerSuccess'));
        }

        // Reused as a login-time forced-setup step, not genuine
        // registration - the user was already active, so there's no
        // account to activate, and they should land wherever a normal
        // login sends them, not wherever a fresh registration does.
        return redirect()->to(config('Auth')->loginRedirect())
            ->with('message', lang('TotpMfa.successMessage'));
    }

    public function getType(): string
    {
        return TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE;
    }

    /**
     * The activation identity is actually created inside
     * beginEnrollment() (called from show(), since that's where the
     * plaintext secret is needed to build the QR code) - this call
     * just ensures it exists before Shield's own pending-check runs
     * immediately afterward. beginEnrollment() is idempotent-safe, so
     * calling it here and again from show() doesn't create duplicates
     * or regenerate an already-valid secret.
     */
    public function createIdentity(User $user): string
    {
        $enrollment = $this->store->beginEnrollment($user, $user->email ?? ('user-' . $user->id));

        return $enrollment['secret'];
    }

    protected function getPendingUser(): User
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $user          = $authenticator->getPendingUser();

        if ($user === null) {
            throw new RuntimeException('TotpActivator: cannot get the pending registration user.');
        }

        return $user;
    }
}
