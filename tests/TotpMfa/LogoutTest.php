<?php

declare(strict_types=1);

namespace Tests\TotpMfa;

use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use TotpMfa\Libraries\Totp;
use TotpMfa\Libraries\TotpIdentityStore;

/**
 * Sanity check that logging out behaves normally for a user with TOTP
 * enrolled. This package never touches Shield's own logout route
 * (confirmed as GET /logout, named route 'logout', handled by
 * Shield's own LoginController::logoutAction) - this test is really
 * confirming there's no unexpected interaction, not re-testing
 * Shield's logout implementation itself.
 */
final class LogoutTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
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

    public function testEnrolledUserCanLogOutNormally(): void
    {
        $user = fake(UserModel::class, [
            'email'    => 'logout-test-' . uniqid() . '@example.com',
            'username' => 'logouttest' . uniqid(),
            'password' => 'secret123456',
        ]);

        $this->actingAs($user);

        $store      = new TotpIdentityStore();
        $totp       = new Totp();
        $enrollment = $store->beginEnrollment($user, $user->email);
        $store->confirmEnrollment($user, $totp->currentCode($enrollment['secret']));

        $this->assertTrue(auth()->loggedIn());

        $this->get('/logout');

        $this->assertFalse(auth()->loggedIn());
    }
}
