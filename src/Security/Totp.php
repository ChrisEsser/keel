<?php

declare(strict_types=1);

namespace Keel\Security;

// Hand-rolled RFC 6238 TOTP (compatible with Google Authenticator, Authy, 1Password, etc).
// No external dependency -- keeps the framework's zero-Composer-dependency stance.
class Totp
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    // $issuer is what the user sees naming this account in their authenticator app. Required
    // rather than defaulted: this is the framework tier, so it has no business knowing the
    // application's name -- callers pass Keel\Brand::name().
    public static function provisioningUri(string $secret, string $accountEmail, string $issuer): string
    {
        $label = rawurlencode("$issuer:$accountEmail");
        $query = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);

        return "otpauth://totp/$label?$query";
    }

    public static function verify(string $base32Secret, string $code, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $secret = self::base32Decode($base32Secret);
        $currentStep = (int) floor(time() / self::PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($secret, $currentStep + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    private static function codeAt(string $secret, int $step): string
    {
        $counter = pack('N*', 0) . pack('N*', $step);
        $hash = hash_hmac('sha1', $counter, $secret, true);
        $offset = ord($hash[19]) & 0x0f;

        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $base32));

        $bits = '';
        foreach (str_split($base32) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) < 8) continue;
            $bytes .= chr(bindec($byte));
        }

        return $bytes;
    }
}
