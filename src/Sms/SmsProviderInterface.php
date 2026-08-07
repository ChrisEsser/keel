<?php

declare(strict_types=1);

namespace Framework\Sms;

interface SmsProviderInterface
{
    public function send(string $to, string $body): bool;
}
