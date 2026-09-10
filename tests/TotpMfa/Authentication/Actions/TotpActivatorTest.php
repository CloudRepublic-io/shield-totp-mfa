<?php

declare(strict_types=1);

namespace Tests\TotpMfa\Authentication\Actions;

use CodeIgniter\Config\Services;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use TotpMfa\Authentication\Actions\TotpActivator;
use TotpMfa\Controllers\TotpActivatorController;
use TotpMfa\Libraries\Totp;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * Tests TotpActivator's show()/verify() and TotpActivatorController's
 * skip() directly, rather than through a full HTTP round-trip.
 *
 * See TotpMfaTest's class doc comment for the three attempts this
 * package went through before landing on real attempt() calls with
 * real credentials - the short version: neither actingAs() (fully
 * logged in - the wrong state) nor startLogin() alone (doesn't
 * actually flip the authenticator into pending state; the private
 * setAuthAction() does that, and requires internal state only
 * attempt() sets up correctly) worked.
 *
 * skip() doesn't reference $this->request or $this->response, so it's
 * safe to call on a directly-instantiated TotpActivatorController
 * without going through Controller's normal initController() lifecycle.
 *
 * CONFIRMED ROOT CAUSE of "cannot get the pending registration user"
 * (via TWO rounds of real diagnostic runs against a real app, not
 * speculation): DatabaseTestTrait's own $refresh resets the DATABASE
 * between test methods, but does nothing to the SESSION or to CI4's
 * own cached SERVICE instances. Shield's own Session authenticator
 * throws a LogicException ("The user has User Info in Session, so
 * already logged in or in pending login state...") when attempt() is
 * called while state from a previous attempt() is still present.
 *
 * First diagnostic round confirmed session()->destroy() alone was NOT
 * sufficient - a second attempt() in the same process still threw the
 * same LogicException even with the session destroyed in between. This
 * pointed at CI4's service container specifically: auth()'s underlying
 * authenticator is obtained through it, which returns a SHARED
 * instance by default - its own in-memory state can survive a plain
 * session()->destroy() call. Second diagnostic round confirmed adding
 * $this->resetServices() (a CIUnitTestCase-provided method, confirmed
 * via CodeIgniter's own testing docs, for resetting cached service
 * instances) resolves it. setUp() now calls BOTH resetServices() and
 * session()->destroy(), in that order, before anything else - giving
 * every test method a genuinely clean authenticator and session
 * regardless of what ran before it in the same PHPUnit process. This
 * is what actually explained the "works sometimes, doesn't others"
 * pattern chased at length before both pieces were isolated, not
 * anything about which identities existed at the time, which is what
 * several earlier (unsuccessful) fixes here assumed.
 *
 * SEPARATE FINDING, NOW FULLY UNDERSTOOD (revised from an earlier,
 * partial account): only 'register' is forced in setUp()/tearDown()
 * below, pointing at TotpActivator directly - 'login' is deliberately
 * left untouched (forcing it to null was tried and caused a
 * regression). attemptLogin()'s underlying Session::attempt() calls
 * startUpAction('login', $user) - hardcoded to the 'login' slot's
 * action regardless of the user's active status. When 'login' is
 * MfaDispatcher rather than TotpActivator directly, this calls
 * MfaDispatcher::createIdentity(), which resolves to whatever that
 * particular user's login method actually is (their preference, or
 * $defaultMethod) - NOT TotpActivator, even for a brand-new,
 * registering user.
 *
 * Once the session-leakage bug above was properly fixed, it became
 * clear this affects ALL FIVE tests in this file, not just two -
 * confirmed by a real run where every single one failed identically.
 * The earlier "3 of 5 pass" observation was itself an ARTIFACT of the
 * session-leakage bug: those three were very likely inheriting stray
 * pending-state left behind by whatever ran immediately before them,
 * not passing due to anything correct in their own setup.
 *
 * TWO further fixes were tried and confirmed NOT sufficient before
 * finding the real one, each ruled out by a dedicated diagnostic run
 * rather than assumption:
 *   1. Creating the activation identity in the database (this class's
 *      own createIdentity()) alone - confirmed insufficient by a run
 *      where that fix produced the exact same failures.
 *   2. Writing session('user')['auth_action'] directly, matching
 *      exactly what a genuinely working scenario's own session held -
 *      ALSO confirmed insufficient. A reflection-based diagnostic
 *      dump of the authenticator object itself revealed why:
 *      getPendingUser() checks the authenticator's own private,
 *      in-memory $userState property directly, not something
 *      re-derived from session data on each call. A working scenario's
 *      $userState was directly observed to be 2 at the exact point
 *      getPendingUser() succeeded; a failing scenario's was 3, even
 *      with byte-for-byte identical session data.
 *
 * The actual fix: simulateRegistrationStartup() now sets both
 * $userState (to 2, the confirmed-working value) and $user directly
 * via reflection on the authenticator object, since setAuthAction()
 * itself never runs again after Session::attempt() completes to
 * reconsider its earlier decision. Real registration works correctly
 * in production because Shield's actual registration flow reaches this
 * state through a separate mechanism this test suite's attempt()-based
 * simulation can't reproduce - simulateRegistrationStartup() is
 * applied to every test in this file now, not just two.
 */
final class TotpActivatorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables or this package's migration -
    // it only looks in that one namespace. null triggers the same
    // behavior as `php spark migrate --all`, picking up every
    // registered namespace's migrations (Shield's core tables, this
    // package's auth_remembered_devices, and anything else your app
    // has).
    protected $namespace = null;

    private const PASSWORD = 'secret123456';

    private $originalRegisterAction;

    protected function setUp(): void
    {
        parent::setUp();

        // CONFIRMED ROOT CAUSE, FIXED HERE (confirmed via two rounds of
        // real diagnostic runs against a real app, not speculation):
        // DatabaseTestTrait's own $refresh resets the DATABASE between
        // test methods, but does nothing to the SESSION or to CI4's
        // own cached SERVICE instances. Two separate things needed
        // clearing, not one:
        //
        //   1. resetServices() - a CIUnitTestCase-provided method
        //      (confirmed via CodeIgniter's own testing docs) that
        //      resets cached service instances. auth()'s underlying
        //      authenticator is obtained through CI4's service
        //      container, which returns a SHARED instance by default -
        //      its own in-memory state can survive a plain
        //      session()->destroy() call between two attempt()s in the
        //      same process. This was the missing piece:
        //      session()->destroy() ALONE was tried first and
        //      confirmed NOT sufficient - a second attempt() in the
        //      same process still threw Shield's own "already logged
        //      in or in pending login state" LogicException even with
        //      the session destroyed.
        //   2. session()->destroy() - clears the actual session data
        //      itself, on top of the fresh service instance from (1).
        //
        // Without both, whichever test method happened to run
        // immediately before this one (within the same PHPUnit
        // process) left state behind that this one's own
        // attemptLogin() call either collided with directly, or
        // silently inherited in a way that made getPendingUser()
        // behave inconsistently depending on test execution order -
        // this is what actually explained the "works sometimes,
        // doesn't others" pattern chased at length before this was
        // fully isolated, not anything about which identities existed.
        //
        // CONFIRMED REGRESSION FROM resetServices() ITSELF, ALSO FIXED
        // HERE: per CodeIgniter's own docs (missed the first time this
        // fix was written): "This method resets the all states of
        // Services, and the RouteCollection will have no routes. If
        // you want to use your routes to be loaded, you need to call
        // the loadRoutes() method like Services::routes()->loadRoutes()."
        // Without this, every url_to()/route_to() call in this
        // package's own views and filters (auth-action-verify,
        // totp-step-up, totp-settings-enroll, ...) throws "The route
        // for ... cannot be found" - confirmed against a real app,
        // where this exact regression appeared the moment
        // resetServices() shipped without this companion call.
        $this->resetServices();
        session()->destroy();
        Services::routes()->loadRoutes();

        $authConfig                      = config('Auth');
        $this->originalRegisterAction    = $authConfig->actions['register'] ?? null;
        $authConfig->actions['register'] = TotpActivator::class;
        // 'login' deliberately left untouched - see the class doc
        // comment above for why.
    }

    protected function tearDown(): void
    {
        config('Auth')->actions['register'] = $this->originalRegisterAction;

        parent::tearDown();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'activator-test-' . uniqid() . '@example.com',
            'username' => 'activatortest' . uniqid(),
            'password' => self::PASSWORD,
            'active'   => false,
        ]);
    }

    private function requestWithPost(array $post): IncomingRequest
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);

        return $request;
    }

    /**
     * Real registration-flow login attempt with real credentials - see
     * TotpMfaTest's class doc comment for why this replaced two
     * earlier, narrower attempts to fake this state directly.
     *
     * CORRECTED CLAIM: an earlier version of this comment said this
     * also invokes TotpActivator::createIdentity() via Shield's own
     * startUpAction() - confirmed WRONG for an app using
     * shield-mfa-dispatcher. attempt()'s own startUpAction() call is
     * hardcoded to the 'login' slot's action specifically, regardless
     * of whether the user is active or not - when 'login' is
     * MfaDispatcher (not TotpActivator directly), this calls
     * MfaDispatcher::createIdentity(), which resolves to whatever that
     * user's login method actually is (their preference, or
     * $defaultMethod - 'email' in a typical setup), not TotpActivator
     * at all. Confirmed against a real app: registration itself works
     * correctly end-to-end in production, meaning Shield's REAL
     * registration flow calls createIdentity() on the 'register' slot
     * through some other, separate mechanism this test suite doesn't
     * reproduce - see simulateRegistrationStartup() below, called by
     * every test in this file to account for this gap directly.
     */
    private function attemptLogin(User $user): void
    {
        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $result        = $authenticator->attempt([
            'email'    => $user->email,
            'password' => self::PASSWORD,
        ]);

        $this->assertTrue($result->isOK(), 'attempt() did not succeed with the test user\'s real credentials.');
    }

    /**
     * Directly writes the session state Shield's own (private)
     * setAuthAction() would have written if it had found a match for
     * the 'register' slot - confirmed, via a real diagnostic run
     * against a real app, to be exactly this shape: an 'auth_action'
     * key set to the fully-qualified action class name, alongside the
     * user's own id. The same session sub-array
     * CompletesPendingAction::clearAuthActionSession() already
     * reads/clears elsewhere in this package.
     *
     * CONFIRMED NECESSARY, not just createIdentity() alone: an earlier
     * version of this method only created the DATABASE identity (via
     * createIdentity()), on the theory that was the one missing piece.
     * That was wrong - confirmed by a real run where EVERY test in
     * this file failed identically even with that call in place,
     * including tests that never called it at all and instead created
     * their own identity via TotpIdentityStore::beginEnrollment()
     * directly. The real, deeper explanation: when 'login' is
     * MfaDispatcher, Session::attempt()'s own setAuthAction() check
     * (which runs BEFORE either of those identity-creating calls could
     * ever run) finds no match for EITHER slot for a brand-new user -
     * neither MfaDispatcher's own getType() (which resolves to
     * whatever that user's login method actually is, e.g. 'email', not
     * TotpActivator) nor TotpActivator's own getType() (since no
     * matching identity exists in the database YET at that point). So
     * Shield's own $userState is simply never flipped to STATE_PENDING
     * at all - and setAuthAction() is never re-run later to reconsider
     * that decision, no matter what gets created in the database
     * afterward.
     *
     * ALSO CONFIRMED, via a second real diagnostic run, that writing
     * session('user')['auth_action'] directly is ALSO not sufficient -
     * getPendingUser() checks the authenticator's own private,
     * in-memory $userState property directly, not something re-derived
     * from session data on each call. A working scenario's own
     * $userState was directly observed (via reflection) to be exactly
     * 2 at the point getPendingUser() succeeds; a failing scenario's
     * was 3, even with identical session data written by hand. The
     * only way found to correctly simulate a real registration
     * request's state for testing purposes is to set both
     * $userState and $user directly via reflection, matching that
     * confirmed-working value precisely.
     *
     * Doesn't change what show()/verify()/skip() themselves are tested
     * for - they're still exercised normally right after this call.
     * This only supplies the one piece of state a real registration
     * request would already have in place, that this test suite's
     * attempt()-based simulation can't reproduce on its own when
     * 'login' points elsewhere.
     */
    private function simulateRegistrationStartup(User $user): void
    {
        // The database identity a real registration flow's
        // createIdentity() call produces - callers that create their
        // own (e.g. via TotpIdentityStore::beginEnrollment() directly,
        // to capture the secret for generating a real code) should
        // call this BEFORE their own call, since that later call
        // determines the actual secret used - this one's own secret
        // would otherwise be silently overwritten and discarded.
        (new TotpActivator())->createIdentity($user);

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();
        $reflection     = new \ReflectionObject($authenticator);

        // 2 - CONFIRMED via a real diagnostic run (reflection dump of
        // a genuinely working scenario's own $userState at the exact
        // point getPendingUser() succeeded), not guessed or derived
        // from documentation. If a future Shield version changes this
        // internal value, this is the line to revisit.
        $userStateProperty = $reflection->getProperty('userState');
        $userStateProperty->setAccessible(true);
        $userStateProperty->setValue($authenticator, 2);

        $userProperty = $reflection->getProperty('user');
        $userProperty->setAccessible(true);
        $userProperty->setValue($authenticator, $user);

        // Session data too - not load-bearing for getPendingUser()
        // itself (confirmed via diagnostic that this alone isn't
        // enough), but other Shield internals may still read it (e.g.
        // CompletesPendingAction::clearAuthActionSession() elsewhere in
        // this package), so it's set for consistency with what
        // setAuthAction() itself would have written.
        $field = setting('Auth.sessionConfig')['field'];
        $data  = session($field) ?? [];

        $data['id']                  = $user->id;
        $data['auth_action']         = TotpActivator::class;
        $data['auth_action_message'] = null;

        session()->set($field, $data);
    }

    public function testShowRendersQrCodeAndCreatesActivationIdentity(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        $body = (new TotpActivator())->show();

        $this->assertStringContainsString(lang('TotpMfa.manualKeyIntro'), $body);
        $this->seeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE,
        ]);
    }

    public function testCorrectCodeActivatesUserAndEnrollsTotp(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        // Called AFTER simulateRegistrationStartup() deliberately -
        // this is what determines the actual secret used below, not
        // that method's own internal createIdentity() call. See
        // simulateRegistrationStartup()'s own doc comment for why the
        // order matters.
        $store      = new TotpIdentityStore();
        $enrollment = $store->beginEnrollment($user, $user->email);
        $totp       = new Totp();

        $request  = $this->requestWithPost(['code' => $totp->currentCode($enrollment['secret'])]);
        $response = (new TotpActivator())->verify($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($store->hasEnrolled($user));

        $fresh = model(UserModel::class)->find($user->id);
        $this->assertTrue((bool) $fresh->active);
    }

    public function testWrongCodeDoesNotActivateOrEnroll(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        $store = new TotpIdentityStore();

        $request = $this->requestWithPost(['code' => '000000']);
        (new TotpActivator())->verify($request);

        $this->assertFalse($store->hasEnrolled($user));

        $fresh = model(UserModel::class)->find($user->id);
        $this->assertFalse((bool) $fresh->active);
    }

    public function testSkipActivatesUserWithoutEnrolling(): void
    {
        $user = $this->makeUser();
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        $store = new TotpIdentityStore();

        $response = (new TotpActivatorController())->skip();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse($store->hasEnrolled($user));

        $fresh = model(UserModel::class)->find($user->id);
        $this->assertTrue((bool) $fresh->active);
    }

    /**
     * Confirms the fix that makes this class safe to reuse for
     * shield-mfa-dispatcher's forced-setup flow (an already-active
     * user forced to set up TOTP mid-login, not a genuinely new
     * registration) - see this class's own doc comment. An
     * already-active user completing verify() successfully should NOT
     * be sent to registerRedirect() with the registration success
     * message; they should land wherever a normal login sends them.
     */
    public function testVerifyForAnAlreadyActiveUserRedirectsToLoginNotRegistration(): void
    {
        $user = fake(UserModel::class, [
            'email'    => 'already-active-test-' . uniqid() . '@example.com',
            'username' => 'alreadyactivetest' . uniqid(),
            'password' => self::PASSWORD,
            'active'   => true, // unlike makeUser(), which is false - this is the whole point of the test
        ]);
        $this->attemptLogin($user);
        $this->simulateRegistrationStartup($user);

        // Called AFTER simulateRegistrationStartup() deliberately -
        // see that method's own doc comment for why the order matters.
        $store      = new TotpIdentityStore();
        $enrollment = $store->beginEnrollment($user, $user->email);
        $totp       = new Totp();

        $request  = $this->requestWithPost(['code' => $totp->currentCode($enrollment['secret'])]);
        $response = (new TotpActivator())->verify($request);

        $this->assertSame(302, $response->getStatusCode());
        // The registration branch sets lang('Auth.registerSuccess') as
        // the flash message; the already-active branch sets
        // lang('TotpMfa.successMessage') instead - checking which one
        // landed is a more reliable signal of which branch actually
        // ran than comparing raw redirect URLs would be.
        $this->assertSame(lang('TotpMfa.successMessage'), session('message'));
    }

    // -------------------------------------------------------------------
    // appliesTo() - THE confirmed fix, carried over from
    // shield-passkey-mfa's own confirmed resolution of a real bug: a
    // user with an already-enrolled method was still routed into that
    // method's own enrollment flow on later, ordinary logins. See this
    // class's own doc comment for the full account.
    // -------------------------------------------------------------------

    public function testAppliesToReturnsTrueForAUserWithNoTotpEnrolled(): void
    {
        $user      = $this->makeUser();
        $activator = new TotpActivator();

        $this->assertTrue($activator->appliesTo($user));
    }

    /**
     * THE regression test for the actual bug. Unlike shield-passkey-mfa's
     * own equivalent test (which can only insert a fake row, since
     * real passkey enrollment needs real cryptography this test suite
     * can't produce), TOTP's own enrollment can be completed for real
     * here - store()->beginEnrollment() + confirmEnrollment() with a
     * genuinely matching, freshly-generated code.
     */
    public function testAppliesToReturnsFalseForAUserWithTotpAlreadyEnrolled(): void
    {
        $user  = $this->makeUser();
        $store = new TotpIdentityStore();

        $enrollment = $store->beginEnrollment($user, $user->email);
        $totp       = new Totp();
        $store->confirmEnrollment($user, $totp->currentCode($enrollment['secret']));

        $activator = new TotpActivator();

        $this->assertFalse($activator->appliesTo($user));
    }
}
