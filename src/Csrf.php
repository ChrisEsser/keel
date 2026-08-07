<?php

declare(strict_types=1);

namespace Framework;

class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verify(?string $submitted): bool
    {
        return isset($_SESSION['csrf_token']) && $submitted !== null
            && hash_equals($_SESSION['csrf_token'], $submitted);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(static::token()) . '">';
    }
}
