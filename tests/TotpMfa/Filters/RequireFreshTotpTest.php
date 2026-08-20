<?php

declare(strict_types=1);

namespace Tests\TotpMfa\Filters;

use CodeIgniter\Config\Services;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use TotpMfa\Filters\RequireFreshTotp;
use TotpMfa\Libraries\Totp;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * Tests RequireFreshTotp::before() directly, rather than through a
 * full HTTP round-trip to a real protected route - the filter only
 * needs a request object and an "acting as" user, both of which
 * CIUnitTestCase + AuthenticationTesting already provide, so there's
 * no need for your app to have a dedicated test-only protected route
 * wired up just to exercise this.
 */
final class RequireFreshTotpTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AuthenticationTesting;

    protected $refresh = true;

    // DatabaseTestTrait's own default ($namespace = 'Tests\Support')
    // does NOT migrate Shield's own tables or this package's migration -
    // it only looks in that one namespace. null triggers the same
    // behavior as `php spark migrate --all`, picking up every
    // registered namespace's migrations (Shield's core tables, this
    // package's auth_remembered_devices, and anything else your app
    // has).
    protected $namespace = null;

    /**
     * Saved/restored around the one test that mutates a shared config
     * property - see tearDown().
     */
    private ?bool $originalStepUpRequiresEnrollment = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Makes this file self-contained rather than dependent on
        // TotpActivatorTest/TotpMfaTest happening to run first in the
        // same PHPUnit process: this filter's redirect()->route() calls
        // need a populated route collection, and a call to
        // resetServices() anywhere earlier in the same process (this
        // package's own tests call it - see TotpActivatorTest's own
        // setUp() for why) wipes it. loadRoutes() is safe to call even
        // if routes are already loaded.
        Services::routes()->loadRoutes();
    }

    protected function tearDown(): void
    {
        if ($this->originalStepUpRequiresEnrollment !== null) {
            config('TotpMfa')->stepUpRequiresEnrollment = $this->originalStepUpRequiresEnrollment;
            $this->originalStepUpRequiresEnrollment      = null;
        }

        parent::tearDown();
    }

    private function makeUser(string $prefix): User
    {
        return fake(UserModel::class, [
            'email'    => $prefix . '-' . uniqid() . '@example.com',
            'username' => $prefix . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    /**
     * Enrolls an already-created user. Deliberately a separate step
     * from user creation/actingAs() - see the class doc comment on
     * TotpMfaTest for why enrollment has to happen AFTER actingAs(),
     * not before: Shield's own actingAs() test helper uses login()
     * internally, which refuses if the user already has identities
     * for a configured action.
     */
    private function enroll(User $user): void
    {
        $store      = new TotpIdentityStore();
        $totp       = new Totp();
        $enrollment = $store->beginEnrollment($user, $user->email);
        $store->confirmEnrollment($user, $totp->currentCode($enrollment['secret']));
    }

    public function testUnauthenticatedRequestIsIgnored(): void
    {
        $filter = new RequireFreshTotp();

        $result = $filter->before(service('request'));

        // Not this filter's job - it defers to whatever login-required
        // filter runs alongside it.
        $this->assertNull($result);
    }

    public function testEnrolledUserWithoutRecentStepUpIsRedirectedToChallenge(): void
    {
        $user = $this->makeUser('stepup-test');
        $this->actingAs($user);
        $this->enroll($user);

        $result = (new RequireFreshTotp())->before(service('request'));

        $this->assertNotNull($result);
    }

    public function testFreshStepUpSessionLetsTheRequestThrough(): void
    {
        $user = $this->makeUser('stepup-test');
        $this->actingAs($user);
        $this->enroll($user);

        session()->set(config('TotpMfa')->stepUpSessionKey, time());

        $result = (new RequireFreshTotp())->before(service('request'));

        $this->assertNull($result);
    }

    public function testExpiredStepUpSessionIsRedirectedAgain(): void
    {
        $user = $this->makeUser('stepup-test');
        $this->actingAs($user);
        $this->enroll($user);

        $config = config('TotpMfa');
        session()->set($config->stepUpSessionKey, time() - $config->stepUpFreshnessSeconds - 60);

        $result = (new RequireFreshTotp())->before(service('request'));

        $this->assertNotNull($result);
    }

    public function testUnenrolledUserPassesThroughByDefault(): void
    {
        $user = $this->makeUser('stepup-unenrolled');

        $this->actingAs($user);

        $result = (new RequireFreshTotp())->before(service('request'));

        // Default policy: nothing to challenge them with, so let them
        // through rather than lock them out entirely - see
        // $config->stepUpRequiresEnrollment to change this.
        $this->assertNull($result);
    }

    public function testUnenrolledUserIsRedirectedToEnrollWhenRequired(): void
    {
        $user = $this->makeUser('stepup-forced');

        $this->actingAs($user);

        // Saved so tearDown() can restore it - config objects are
        // cached/shared by CodeIgniter's Factories, so mutating one
        // directly without restoring it would leak into every other
        // test that runs afterward in the same PHPUnit process,
        // regardless of which test class they're in.
        $config                                = config('TotpMfa');
        $this->originalStepUpRequiresEnrollment = $config->stepUpRequiresEnrollment;
        $config->stepUpRequiresEnrollment       = true;

        $result = (new RequireFreshTotp())->before(service('request'));

        $this->assertNotNull($result);
    }
}
