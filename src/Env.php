<?php

declare(strict_types=1);

namespace Keel;

/**
 * A small .env parser: KEY=value lines into $_ENV.
 *
 * Deliberately not a full dotenv implementation — no variable interpolation, no multi-line values,
 * no export prefixes. Everything arrives as a string, and a blank value is the same as an absent
 * one, which is what lets `$_ENV['X'] ?? ''` be the idiom everywhere else.
 *
 * What it DOES handle, because leaving these out produces failures far from their cause:
 *
 *   - Surrounding quotes are stripped. `DB_HOST="localhost"` must not connect to `"localhost"`,
 *     quotes included, and fail DNS resolution on a hostname nobody typed.
 *   - Inline `#` comments on unquoted values. A password containing a `#` therefore has to be
 *     quoted, which is the same rule every other dotenv follows.
 *   - Lines with no `=` are skipped rather than fatal.
 */
class Env
{
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Env file not found: $path");
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = self::parseValue(trim($value));
        }
    }

    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote) && strlen($value) >= 2) {
            // Quoted: take it verbatim. A '#' inside quotes is part of the value, which is exactly
            // why a password with one in it has to be quoted.
            return substr($value, 1, -1);
        }

        // Unquoted: an inline comment ends the value. Requiring whitespace before the '#' keeps a
        // bare value like `pass#1` intact -- only ` # comment` is treated as a comment.
        $value = (string) preg_replace('/\s+#.*$/', '', $value);

        return trim($value);
    }
}
