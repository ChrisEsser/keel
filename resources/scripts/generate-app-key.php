<?php

declare(strict_types=1);

// Prints a fresh APP_ENCRYPTION_KEY line ready to paste into config/.env.

echo 'APP_ENCRYPTION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) . "\n";
