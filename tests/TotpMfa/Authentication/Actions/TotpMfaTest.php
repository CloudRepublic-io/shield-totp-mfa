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
use TotpMfa\Authentication\Actions\TotpMfa;
use TotpMfa\Libraries\Totp;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * Tests the login-time TOTP action's show()/verify() methods directly,
 * rather than through a full HTTP round-trip to /auth/a/show and
 * /auth/a/verify.
 *
 * This went through three attempts before landing here - worth reading
 * if this file needs touching again:
 *
 *   1. Shield's own documented HTTP-level testing pattern (actingAs() +
 *      withSession() + FeatureTestTrait's get()/post()) hit Shield's own
 *      registered routes returning PageNotFoundException when dispatched
 *      through FeatureTestTrait - despite route_to() confirming the route
 *      existed moments earlier, and despite this package's own custom
 *      routes (registered the same way) working fine through the
 *      identical mechanism. Never fully root-caused, only inferred from
 *      behavior - calling the action directly sidesteps the question.
 *   2. actingAs($user) alone was tried next - but that's Shield's login(),
 *      a fully-logged-in state, not "mid-login, pending an action", which
 *      is what getPendingUser() checks for. Failed identically every time.
 *   3. auth('session')->getAuthenticator()->startLogin($user) alone was
 *      tried third, on the theory that Shield's own error message ("use
 *      startLogin() instead") named the right method. Also failed
 *      identically - startLogin() turns out not to be what actually
 *      flips the authenticator into a pending state. Shield's real
 *      attempt() (pasted into this project's chat history early on)
 *      shows the actual sequence: `$this->user = $user;` is set directly
 *      as the very first step, and the private setAuthAction() method -
 *      the thing that actually sets $userState to pending - requires
 *      that property to already be non-null. There's no public method
 *      that does just that one step in isolation.
 *
 * So: call the real, public attempt() with real credentials instead of
 * trying to reproduce pieces of its internals. Since this package's own
 * getType()/createIdentity() are what Shield's pending-check queries,
 * this also has the advantage of exercising that logic for real rather
 * than assuming it.
 *
 * CONFIRMED, REAL ISSUE this fix addresses: an earlier version of this
 * class only documented "assumes TotpMfa is registered as 'login'" as
 * a prerequisite comment, rather than actually forcing it. That broke
 * silently for an app using shield-mfa-dispatcher, where
 * Config\Auth::$actions['login'] correctly points at MfaDispatcher,
 * not TotpMfa directly - setAuthAction() then checks MfaDispatcher's
 * own getType() (which may or may not resolve to 'totp' depending on
 * that user's preference/group), not TotpMfa's, so getPendingUser()
 * fails inconsistently depending on what actually matches, not on
 * anything wrong with TotpMfa itself. setUp()/tearDown() below now
 * force and restore the config directly, making this file self-
 * contained regardless of whatever the test-running app's own
 * app/Config/Auth.php actually says - the same fix already applied to
 * shield-mfa-dispatcher's own MfaDispatcherTest for the identical
 * reason.
 *
 * ALSO: setUp() calls $this->resetServices() and session()->destroy()
 * before anything else - a separate, confirmed issue found while
 * debugging TotpActivatorTest's own equivalent failures (see that
 * class's doc comment for the full, two-round diagnostic story):
 * DatabaseTestTrait resets the database between test methods but not
 * the session or CI4's own cached service instances, and a leftover
 * authenticator/session from whichever test ran immediately before
 * this one can make attemptLogin() collide with Shield's own "already
 * logged in or in pending login state" guard. Every test in this file
 * passed without this addition, but it's added defensively anyway,
 * since the same underlying mechanism applies here too and there's no
 * guarantee a different test execution order wouldn't surface it.
 */
final class TotpMfaTest extends CIUnitTestCase
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

    private $originalLoginAction;

    protected function setUp(): void
    {
        parent::setUp();

        // CONFIRMED ROOT CAUSE, FIXED HERE: see TotpActivatorTest's own
        // setUp() for the full explanation, confirmed via two rounds of
        // real diagnostic runs - DatabaseTestTrait resets the database
        // between test methods but not the session or CI4's own cached
        // service instances. Both resetServices() (CIUnitTestCase's own
        // method for resetting cached services, including auth()'s
        // underlying authenticator - the actual missing piece,
        // confirmed necessary after session()->destroy() alone was
        // tried first and confirmed NOT sufficient) and
        // session()->destroy() (clearing the session data itself) are
        // needed together.
        //
        // ALSO: resetServices() itself wipes the route collection
        // ("the RouteCollection will have no routes", per CodeIgniter's
        // own docs) - without reloading it, every url_to()/route_to()
        // call in this package's own views throws "The route for ...
        // cannot be found". Confirmed as a real regression this fix
        // itself introduced before this line was added.
        $this->resetServices();
        session()->destroy();
        Services::routes()->loadRoutes();

        $authConfig                   = config('Auth');
        $this->originalLoginAction    = $authConfig->actions['login'] ?? null;
        $authConfig->actions['login'] = TotpMfa::class;
    }

    protected function tearDown(): void
    {
        config('Auth')->actions['login'] = $this->originalLoginAction;

        parent::tearDown();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'totp-login-test-' . uniqid() . '@example.com',
            'username' => 'totplogintest' . uniqid(),
            'password' => self::PASSWORD,
        ]);
    }

    private function enroll(User $user): string
    {
        $store      = new TotpIdentityStore();
        $totp       = new Totp();
        $enrollment = $store->beginEnrollment($user, $user->email);
        $store->confirmEnrollment($user, $totp->currentCode($enrollment['secret']));

        return $enrollment['secret'];
    }

    /**
     * A fresh IncomingRequest carrying the given POST data.
     *
     * setGlobal('post', ...) is what actually makes this work on
     * CodeIgniter 4.7+: from 4.7, a request reads POST data from the
     * shared 'superglobals' service, which snapshots $_POST once, the
     * first time anything asks for it (attempt() does, well before this
     * runs). Assigning $_POST afterwards is invisible to the request, so
     * getPost('code') came back null - the correct-code and
     * remember-device tests failed, and the wrong/empty-code tests were
     * only passing because every code looked empty. setGlobal() exists
     * on 4.6 too, so this works on both; $_POST is still set for
     * anything that reads it directly.
     */
    private function requestWithPost(array $post): IncomingRequest
    {
        $_POST = $post;

        /** @var IncomingRequest $request */
        $request = service('request', null, false);
        $request->setGlobal('post', $post);

        return $request;
    }

    /**
     * Real login attempt with real credentials - see the class doc
     * comment for why this replaced two earlier, narrower attempts to
     * fake this state directly.
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

    public function testEnrolledUserSeesCodeEntryForm(): void
    {
        $user = $this->makeUser();
        $this->enroll($user);
        $this->attemptLogin($user);

        $body = (new TotpMfa())->show();

        $this->assertStringContainsString(lang('TotpMfa.codeLabel'), $body);
    }

    public function testCorrectCodeCompletesLogin(): void
    {
        $user   = $this->makeUser();
        $secret = $this->enroll($user);
        $this->attemptLogin($user);
        $totp = new Totp();

        $request  = $this->requestWithPost(['code' => $totp->currentCode($secret)]);
        $response = (new TotpMfa())->verify($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotEmpty(session('message'));
    }

    public function testWrongCodeIsRejectedWithoutCompletingLogin(): void
    {
        $user = $this->makeUser();
        $this->enroll($user);
        $this->attemptLogin($user);

        $request = $this->requestWithPost(['code' => '000000']);
        (new TotpMfa())->verify($request);

        $this->assertNotEmpty(session('error'));
    }

    public function testEmptyCodeIsRejected(): void
    {
        $user = $this->makeUser();
        $this->enroll($user);
        $this->attemptLogin($user);

        $request = $this->requestWithPost(['code' => '']);
        (new TotpMfa())->verify($request);

        $this->assertNotEmpty(session('error'));
    }

    public function testRememberDeviceCreatesRecordWhenChecked(): void
    {
        $user   = $this->makeUser();
        $secret = $this->enroll($user);
        $this->attemptLogin($user);
        $totp = new Totp();

        $request = $this->requestWithPost([
            'code'            => $totp->currentCode($secret),
            'remember_device' => '1',
        ]);
        (new TotpMfa())->verify($request);

        $this->seeInDatabase('auth_remembered_devices', ['user_id' => $user->id]);
    }

    public function testNoRememberDeviceRecordWhenCheckboxNotSent(): void
    {
        $user   = $this->makeUser();
        $secret = $this->enroll($user);
        $this->attemptLogin($user);
        $totp = new Totp();

        $request = $this->requestWithPost(['code' => $totp->currentCode($secret)]);
        (new TotpMfa())->verify($request);

        $this->dontSeeInDatabase('auth_remembered_devices', ['user_id' => $user->id]);
    }

    /**
     * Requires app/Config/TotpMfa.php to have
     * forceEnrollmentOnNextLogin = true in the test environment -
     * skipped otherwise, since this behavior is opt-in per-app. No
     * enroll() call here deliberately - attempt() itself, via
     * startUpAction(), is what calls TotpMfa::createIdentity() for an
     * unenrolled user when this setting is on.
     */
    public function testForcedEnrollmentShowsQrCodeForUnenrolledUser(): void
    {
        if (! config('TotpMfa')->forceEnrollmentOnNextLogin) {
            $this->markTestSkipped('forceEnrollmentOnNextLogin is not enabled in this environment.');
        }

        $user = $this->makeUser();
        $this->attemptLogin($user);

        $body = (new TotpMfa())->show();

        $this->assertStringContainsString(lang('TotpMfa.enrollCodeLabel'), $body);
    }
}
