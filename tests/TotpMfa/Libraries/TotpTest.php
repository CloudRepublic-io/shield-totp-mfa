<?php

declare(strict_types=1);

namespace Tests\TotpMfa\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use TotpMfa\Libraries\Base32;
use TotpMfa\Libraries\Totp;

/**
 * RFC 6238 TOTP correctness. Two kinds of checks:
 *   - self-consistency (currentCode()'s own output must verify() as
 *     true) - can't be wrong due to a misremembered constant, and is
 *     what actually matters for this package's real behavior.
 *   - the official RFC 6238 Appendix B test vectors (confirmed against
 *     the actual RFC text, not just recalled from memory) - an
 *     independent cross-check against the published spec itself.
 */
final class TotpTest extends CIUnitTestCase
{
    public function testCurrentCodeVerifiesAgainstItself(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        $this->assertTrue($totp->verify($secret, $totp->currentCode($secret)));
    }

    public function testWrongCodeFailsVerification(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        $code      = $totp->currentCode($secret);
        $wrongCode = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->assertNotSame($code, $wrongCode);
        $this->assertFalse($totp->verify($secret, $wrongCode));
    }

    public function testCodeOneStepAheadVerifiesWithinWindow(): void
    {
        $totp   = new Totp(digits: 6, period: 30);
        $secret = $totp->generateSecret();
        $now    = time();

        $oneStepAheadCode = $totp->currentCode($secret, $now + 30);

        $this->assertTrue($totp->verify($secret, $oneStepAheadCode, window: 1, timestamp: $now));
    }

    public function testCodeOutsideWindowFailsVerification(): void
    {
        $totp   = new Totp(digits: 6, period: 30);
        $secret = $totp->generateSecret();
        $now    = time();

        // 5 steps ahead, window is only 1 - should not verify.
        $farFutureCode = $totp->currentCode($secret, $now + (30 * 5));

        $this->assertFalse($totp->verify($secret, $farFutureCode, window: 1, timestamp: $now));
    }

    public function testNonNumericOrEmptyCodeIsRejected(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        $this->assertFalse($totp->verify($secret, 'abcdef'));
        $this->assertFalse($totp->verify($secret, ''));
    }

    public function testProvisioningUriContainsExpectedFields(): void
    {
        $totp   = new Totp();
        $secret = $totp->generateSecret();

        $uri = $totp->provisioningUri($secret, 'jane@example.com', 'My App');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=My%20App', $uri);
        $this->assertStringContainsString(rawurlencode('jane@example.com'), $uri);
    }

    /**
     * Official RFC 6238 Appendix B test vectors: SHA1, 8-digit codes,
     * ASCII secret "12345678901234567890" (base32-encoded here since
     * Totp takes a base32 secret, matching what an authenticator app
     * actually stores). Confirmed against the published RFC text - the
     * first two rows below are verbatim from the spec's own table.
     *
     * @dataProvider rfc6238VectorProvider
     */
    public function testAgainstRfc6238Vectors(int $timestamp, string $expectedCode): void
    {
        $secret = Base32::encode('12345678901234567890');
        $totp   = new Totp(digits: 8, period: 30, algorithm: 'sha1');

        $this->assertSame($expectedCode, $totp->currentCode($secret, $timestamp));
    }

    public static function rfc6238VectorProvider(): array
    {
        return [
            'T=59 (1970-01-01 00:00:59 UTC)'          => [59, '94287082'],
            'T=1111111109 (2005-03-18 01:58:29 UTC)'  => [1_111_111_109, '07081804'],
            'T=1111111111 (2005-03-18 01:58:31 UTC)'  => [1_111_111_111, '14050471'],
            'T=1234567890 (2009-02-13 23:31:30 UTC)'  => [1_234_567_890, '89005924'],
            'T=2000000000 (2033-05-18 03:33:20 UTC)'  => [2_000_000_000, '69279037'],
        ];
    }
}
