<?php

declare(strict_types=1);

namespace Framework\Sms;

class Sms
{
    public function __construct(private SmsProviderInterface $provider) {}

    public function send(string $to, string $body): bool
    {
        return $this->provider->send($to, $body);
    }
}
