<?php

declare(strict_types=1);

namespace TotpMfa\Libraries;

/**
 * Minimal RFC 4648 Base32 codec.
 *
 * TOTP secrets and the otpauth:// provisioning URI both use Base32
 * (Google/Microsoft Authenticator, Authy, etc. all expect this). Written
 * from scratch here so the plugin has zero composer dependencies.
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }

            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    public static function decode(string $data): string
    {
        $data = strtoupper((string) preg_replace('/[^A-Za-z2-7]/', '', $data));

        $bits = '';

        foreach (str_split($data) as $char) {
            $pos = strpos(self::ALPHABET, $char);

            if ($pos === false) {
                continue;
            }

            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) {
                // Trailing padding bits, not a full byte - discard.
                break;
            }

            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }
}
