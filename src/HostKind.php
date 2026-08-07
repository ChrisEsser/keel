<?php

declare(strict_types=1);

namespace Framework;

// Which of an application's surfaces a request belongs to. The front controller matches on this
// to pick a router, so adding a case here means adding a branch there.
enum HostKind
{
    case App;
    case Marketing;
}
