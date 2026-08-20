<?php

declare(strict_types=1);

namespace TotpMfa\Libraries;

/**
 * RFC 6238 (TOTP) / RFC 4226 (HOTP) implementation.
 *
 * Compatible with Google Authenticator, Microsoft Authenticator, Authy,
 * 1Password, etc. - anything that reads a standard otpauth://totp/ URI.
 * Deliberately dependency-free (no composer package required).
 */
final class Totp
{
    public function __construct(
        private readonly int $digits = 6,
        private readonly int $period = 30,
        private readonly string $algorithm = 'sha1',
    ) {
    }

    /**
     * Generates a new random Base32 secret. 20 bytes (160 bits) is the
     * standard secret length used by Google Authenticator and friends,
     * and happens to Base32-encode to exactly 32 characters with no
     * padding.
     */
    public function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes($bytes));
    }

    /**
     * Builds the otpauth:// URI that authenticator apps scan as a QR
     * code. $accountName is typically the user's email/username,
     * $issuer is your app's name (shown above the code in the app).
     */
    public function provisioningUri(string $secretBase32, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);

        $query = http_build_query([
            'secret'    => $secretBase32,
            'issuer'    => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits'    => $this->digits,
            'period'    => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/' . $label . '?' . $query;
    }

    /**
     * The code that would currently be valid for this secret. Mostly
     * useful for tests/debugging - use verify() for real checks.
     */
    public function currentCode(string $secretBase32, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);

        return $this->hotp(Base32::decode($secretBase32), $counter);
    }

    /**
     * Verifies a user-submitted code against the secret, tolerating
     * clock drift of up to $window steps (each step = $period seconds)
     * in either direction. window=1 with the default 30s period means
     * a code is accepted for up to ~90 seconds around when it was shown.
     */
    public function verify(string $secretBase32, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = trim($code);

        if ($code === '' || ! preg_match('/^\d+$/', $code)) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, $this->period);
        $key     = Base32::decode($secretBase32);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->hotp($key, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 4226 HOTP algorithm: HMAC the counter, then truncate the HMAC
     * down to a short numeric code.
     */
    private function hotp(string $key, int $counter): string
    {
        // 8-byte big-endian counter. pack('N*', 0, $counter) writes two
        // 4-byte big-endian unsigned longs: high 4 bytes always zero
        // (counter never exceeds 32 bits within any realistic clock
        // range), low 4 bytes the actual counter.
        $binaryCounter = pack('N*', 0, $counter);

        $hash = hash_hmac($this->algorithm, $binaryCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }
}
