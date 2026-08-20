<?php

declare(strict_types=1);

namespace TotpMfa\Libraries;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Entities\UserIdentity;
use CodeIgniter\Shield\Models\UserIdentityModel;
use Config\TotpMfa as TotpMfaConfig;

/**
 * All TOTP identity read/write logic lives here, used by three
 * different entry points:
 *
 *   - TotpMfa (the 'login' action) - verification only.
 *   - TotpActivator (the 'register' action) - registration-time setup.
 *   - MfaSettingsController (in the shield-mfa-dispatcher package) -
 *     self-service opt-in for existing users, any time.
 *
 * Two identity types are used:
 *
 *   - self::ID_TYPE_TOTP ('totp') - the real, permanent, encrypted
 *     secret. Never deleted except by explicit disable().
 *   - self::ID_TYPE_TOTP_ACTIVATE ('totp_activate') - a short-lived
 *     identity that exists only between "here's your QR code" and
 *     "you've confirmed a valid code", used during registration-time
 *     setup. Deleted the moment enrollment is confirmed (or abandoned
 *     and replaced on a later attempt).
 *
 * Both TotpMfa and TotpActivator are Shield ActionInterface
 * implementations, and their getType()/createIdentity() methods
 * return/operate on WHICHEVER of the two types that specific action is
 * responsible for - never the other one. This split is what makes a
 * permanent, never-deleted secret (the whole point of TOTP) compatible
 * with how Shield decides whether an action is "pending": by checking
 * the database for an identity of getType()'s type. See
 * TotpMfa.php's class doc comment for the full history of why this
 * matters.
 *
 * DELETIONS ARE DELIBERATELY SCOPED AND ISOLATED. Every read or write
 * here gets its own fresh UserIdentityModel instance (via
 * model(UserIdentityModel::class, false)) rather than reusing one
 * cached instance across several chained where()/delete() calls in the
 * same request. This isn't defensive paranoia for its own sake: it
 * rules out an entire class of bug where query-builder conditions from
 * one call could carry over into the next if a shared instance's
 * builder state isn't fully reset between calls. Type-scoped deletes
 * also go through Shield's own deleteIdentitiesByType($user, $type)
 * helper - the same method the reference implementation this package
 * was rebuilt against uses - rather than hand-rolled where()->delete()
 * chains, so the *other* identity types on the same user (most
 * importantly 'email_password' - the thing that actually lets someone
 * log in at all) are never at risk of being touched by a TOTP
 * operation, however that operation is scoped.
 */
class TotpIdentityStore
{
    public const ID_TYPE_TOTP          = 'totp';
    public const ID_TYPE_TOTP_ACTIVATE = 'totp_activate';

    protected TotpMfaConfig $config;
    protected Totp $totp;

    public function __construct()
    {
        $this->config = config('TotpMfa');
        $this->totp    = new Totp($this->config->digits, $this->config->period, $this->config->algorithm);
    }

    /** A fresh, unshared model instance for every call - see class doc comment. */
    protected function identities(): UserIdentityModel
    {
        return model(UserIdentityModel::class, false);
    }

    public function hasEnrolled(User $user): bool
    {
        return $this->findPermanent($user) !== null;
    }

    public function findPermanent(User $user): ?UserIdentity
    {
        return $this->identities()
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_TOTP)
            ->first();
    }

    protected function findActivation(User $user): ?UserIdentity
    {
        return $this->identities()
            ->where('user_id', $user->id)
            ->where('type', self::ID_TYPE_TOTP_ACTIVATE)
            ->first();
    }

    /**
     * Starts (or resumes, if still within its window) a not-yet-confirmed
     * enrollment. Safe to call repeatedly - e.g. once from an Action's
     * createIdentity() and again from its show() - since an
     * already-valid activation is reused rather than replaced.
     *
     * @return array{secret: string, provisioningUri: string, manualKey: string}
     */
    public function beginEnrollment(User $user, string $accountLabel): array
    {
        $activation = $this->findActivation($user);

        if ($activation !== null && $activation->expires->getTimestamp() > time()) {
            $secret = $this->decrypt($activation->secret);
        } else {
            // deleteIdentitiesByType(), not a raw where()->delete() -
            // precisely scoped to this one type, on a fresh model
            // instance, so nothing else on this user is at risk.
            $this->identities()->deleteIdentitiesByType($user, self::ID_TYPE_TOTP_ACTIVATE);

            $secret = $this->totp->generateSecret($this->config->secretBytes);

            $this->identities()->create([
                'user_id' => $user->id,
                'type'    => self::ID_TYPE_TOTP_ACTIVATE,
                'name'    => $this->config->issuer,
                'secret'  => $this->encrypt($secret),
                'extra'   => null,
                'expires' => date('Y-m-d H:i:s', time() + $this->config->enrollmentWindow),
            ]);
        }

        return [
            'secret'          => $secret,
            'provisioningUri' => $this->totp->provisioningUri($secret, $accountLabel, $this->config->issuer),
            'manualKey'       => chunk_split($secret, 4, ' '),
        ];
    }

    /**
     * Checks a submitted code against the pending activation and, on
     * success, promotes it to a permanent identity (replacing any
     * existing one, for the re-enrollment case) and clears the
     * activation. Returns false on a wrong code or a missing/expired
     * activation, without side effects either way.
     */
    public function confirmEnrollment(User $user, string $code): bool
    {
        $activation = $this->findActivation($user);

        if ($activation === null) {
            return false;
        }

        $secret = $this->decrypt($activation->secret);

        if (! $this->totp->verify($secret, $code, $this->config->window)) {
            return false;
        }

        // Precisely scoped to ID_TYPE_TOTP only - re-enrollment case
        // (a user replacing an already-linked authenticator app).
        // Never touches 'email_password' or any other identity type.
        $this->identities()->deleteIdentitiesByType($user, self::ID_TYPE_TOTP);

        $this->identities()->create([
            'user_id' => $user->id,
            'type'    => self::ID_TYPE_TOTP,
            'name'    => $this->config->issuer,
            'secret'  => $this->encrypt($secret),
            'extra'   => null,
            'expires' => null, // permanent
        ]);

        // Delete the activation row specifically by its own primary
        // key, on yet another fresh instance - not by type, and not
        // reusing the instance from the create() call above.
        $this->identities()->delete($activation->id);

        return true;
    }

    /** Abandons a pending enrollment (e.g. a "skip for now" link). */
    public function cancelEnrollment(User $user): void
    {
        $this->identities()->deleteIdentitiesByType($user, self::ID_TYPE_TOTP_ACTIVATE);
    }

    /** Verifies a login-time code against the permanent, enrolled secret. */
    public function verifyLoginCode(User $user, string $code): bool
    {
        $identity = $this->findPermanent($user);

        if ($identity === null) {
            return false;
        }

        return $this->totp->verify($this->decrypt($identity->secret), $code, $this->config->window);
    }

    /**
     * Removes a user's enrolled secret entirely (self-service
     * "disable"), and revokes every device remembered under it.
     *
     * The revocation matters, not just tidiness: a remembered device
     * was only ever trusted in the context of "this browser already
     * passed a TOTP challenge for this account." Leaving those
     * records valid after removing TOTP would let a device skip MFA
     * that no longer exists at all - or, if TOTP is later re-enabled
     * with a new secret, skip a challenge it never actually passed.
     *
     * The remembered-devices half of this uses the query builder
     * directly (db_connect()->table(...)) rather than
     * RememberedDeviceModel's own delete() chain - this removes any
     * dependency on Model-specific delete() argument handling and
     * makes the operation unambiguous: every row in
     * auth_remembered_devices for this user_id, gone, full stop.
     */
    public function disable(User $user): void
    {
        $this->identities()->deleteIdentitiesByType($user, self::ID_TYPE_TOTP);

        db_connect()->table('auth_remembered_devices')
            ->where('user_id', $user->id)
            ->delete();
    }

    public function encrypt(string $secret): string
    {
        return base64_encode(service('encrypter')->encrypt($secret));
    }

    public function decrypt(string $encrypted): string
    {
        return service('encrypter')->decrypt(base64_decode($encrypted, true));
    }
}
