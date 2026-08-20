<?php

declare(strict_types=1);

namespace Tests\TotpMfa\Libraries;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use TotpMfa\Libraries\Totp;
use TotpMfa\Libraries\TotpIdentityStore;
use TotpMfa\Models\RememberedDeviceModel;

/**
 * These need the database - Shield's own auth_identities table, plus
 * this package's auth_remembered_devices table. Make sure both sets
 * of migrations have already been run for whichever DB group your
 * tests use (the same migrations you ran to install this package into
 * your app in the first place).
 */
final class TotpIdentityStoreTest extends CIUnitTestCase
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

    private TotpIdentityStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new TotpIdentityStore();
    }

    private function makeUser(): User
    {
        return fake(UserModel::class, [
            'email'    => 'totp-store-test-' . uniqid() . '@example.com',
            'username' => 'totpstoretest' . uniqid(),
            'password' => 'secret123456',
        ]);
    }

    public function testNotEnrolledByDefault(): void
    {
        $this->assertFalse($this->store->hasEnrolled($this->makeUser()));
    }

    public function testBeginEnrollmentCreatesActivationIdentityButNotEnrollment(): void
    {
        $user       = $this->makeUser();
        $enrollment = $this->store->beginEnrollment($user, $user->email);

        $this->assertNotEmpty($enrollment['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $enrollment['provisioningUri']);

        $this->seeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE,
        ]);

        // Not "enrolled" yet - that only happens once confirmed.
        $this->assertFalse($this->store->hasEnrolled($user));
    }

    public function testBeginEnrollmentReusesAStillValidActivation(): void
    {
        $user = $this->makeUser();

        $first  = $this->store->beginEnrollment($user, $user->email);
        $second = $this->store->beginEnrollment($user, $user->email);

        $this->assertSame($first['secret'], $second['secret']);
    }

    public function testConfirmEnrollmentWithWrongCodeFailsAndChangesNothing(): void
    {
        $user = $this->makeUser();
        $this->store->beginEnrollment($user, $user->email);

        $this->assertFalse($this->store->confirmEnrollment($user, '000000'));
        $this->assertFalse($this->store->hasEnrolled($user));
    }

    public function testConfirmEnrollmentWithCorrectCodeEnrollsAndClearsActivation(): void
    {
        $user       = $this->makeUser();
        $enrollment = $this->store->beginEnrollment($user, $user->email);
        $totp       = new Totp();

        $confirmed = $this->store->confirmEnrollment($user, $totp->currentCode($enrollment['secret']));

        $this->assertTrue($confirmed);
        $this->assertTrue($this->store->hasEnrolled($user));

        $this->dontSeeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => TotpIdentityStore::ID_TYPE_TOTP_ACTIVATE,
        ]);
        $this->seeInDatabase('auth_identities', [
            'user_id' => $user->id,
            'type'    => TotpIdentityStore::ID_TYPE_TOTP,
        ]);
    }

    public function testVerifyLoginCodeAgainstEnrolledSecret(): void
    {
        $user       = $this->makeUser();
        $enrollment = $this->store->beginEnrollment($user, $user->email);
        $totp       = new Totp();
        $this->store->confirmEnrollment($user, $totp->currentCode($enrollment['secret']));

        $this->assertTrue($this->store->verifyLoginCode($user, $totp->currentCode($enrollment['secret'])));
        $this->assertFalse($this->store->verifyLoginCode($user, '000000'));
    }

    public function testDisableRemovesSecretAndAllRememberedDevices(): void
    {
        $user       = $this->makeUser();
        $enrollment = $this->store->beginEnrollment($user, $user->email);
        $totp       = new Totp();
        $this->store->confirmEnrollment($user, $totp->currentCode($enrollment['secret']));

        // Simulate a remembered device for this user.
        model(RememberedDeviceModel::class)->insert([
            'user_id'          => $user->id,
            'selector'         => bin2hex(random_bytes(12)),
            'hashed_validator' => hash('sha256', 'irrelevant-for-this-test'),
            'device_name'      => 'Test Device',
            'expires_at'       => date('Y-m-d H:i:s', time() + 86400),
        ]);
        $this->seeInDatabase('auth_remembered_devices', ['user_id' => $user->id]);

        $this->store->disable($user);

        $this->assertFalse($this->store->hasEnrolled($user));
        $this->dontSeeInDatabase('auth_remembered_devices', ['user_id' => $user->id]);
    }
}
