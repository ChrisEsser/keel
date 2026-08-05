<?php

declare(strict_types=1);

namespace Keel\Sms;

interface SmsProviderInterface
{
    public function send(string $to, string $body): bool;
}
