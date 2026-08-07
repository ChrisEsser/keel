<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

enum TwoFactorMethod: string
{
    case None = 'none';
    case Totp = 'totp';
    case Sms = 'sms';

    public function label(): string
    {
        return match($this) {
            self::None => 'Disabled',
            self::Totp => 'Authenticator app',
            self::Sms  => 'Text message (SMS)',
        };
    }
}
