<?php

declare(strict_types=1);

namespace Tests\TotpMfa\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use TotpMfa\Libraries\Base32;

/**
 * Pure round-trip/format tests for the RFC 4648 Base32 codec - no
 * database or HTTP involved, just PHP-level correctness. Copy this
 * whole tests/TotpMfa directory into your app's own tests/ folder to
 * run these alongside your existing test suite.
 */
final class Base32Test extends CIUnitTestCase
{
    public function testEncodeDecodeRoundTrip(): void
    {
        $original = random_bytes(20);

        $this->assertSame($original, Base32::decode(Base32::encode($original)));
    }

    public function testEncodeUsesOnlyValidAlphabet(): void
    {
        $encoded = Base32::encode(random_bytes(20));

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $encoded);
    }

    public function test20ByteSecretEncodesToExactly32CharactersWithNoPadding(): void
    {
        // 20 bytes * 8 bits = 160 bits / 5 bits-per-symbol = exactly
        // 32 symbols - this is why TOTP secrets are conventionally 20
        // bytes (it's what Google Authenticator itself defaults to).
        $encoded = Base32::encode(random_bytes(20));

        $this->assertSame(32, strlen($encoded));
    }

    public function testDecodeIsCaseInsensitiveAndIgnoresPadding(): void
    {
        $clean = Base32::encode('hello world!');
        $dirty = strtolower($clean) . '====';

        $this->assertSame(Base32::decode($clean), Base32::decode($dirty));
    }

    public function testEmptyStringRoundTrips(): void
    {
        $this->assertSame('', Base32::encode(''));
        $this->assertSame('', Base32::decode(''));
    }
}
