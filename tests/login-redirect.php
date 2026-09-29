<?php
// The sign-in page's ?redirect= only ever goes to a path on this site. Starting with "/" isn't
// enough: "//evil.example" and "/\evil.example" are other websites to a browser, which made
// sign-in an open redirect. No database. Run: php tests/login-redirect.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Framework\Accounts\Controller\AuthController;

$pass = 0; $fail = 0;
function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
}

foreach (['/dashboard', '/organizations/abc/dashboard?tab=billing', '/discount/ABC123', '/a//b'] as $ok) {
    ok(AuthController::isLocalPath($ok), "stays on this site: $ok");
}
foreach (['', 'dashboard', 'https://evil.example', '//evil.example', '//evil.example/login',
          '/\\evil.example', '/\\/evil.example', "/\t/evil.example", "/\n/evil.example", 'javascript:alert(1)'] as $bad) {
    ok(!AuthController::isLocalPath($bad), 'refused: ' . json_encode($bad));
}

echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
