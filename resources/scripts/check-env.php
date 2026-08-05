<?php

declare(strict_types=1);

// Preflight check: verifies this machine can actually run the application before it fails
// mid-request. Run after any PHP or server change, and on a fresh deploy before pointing traffic
// at it.
//
// Nothing in the framework guards its own requirements with extension_loaded(), so a missing
// extension surfaces as a raw fatal in whatever request happens to touch it first. This turns
// that into an answer you get on demand.
//
//   php scripts/check-env.php
//
// Also reachable over the web (php -S / Apache) to check the SERVER's PHP rather than the CLI's,
// which is the pairing that actually matters -- they are separate builds with separate php.ini
// files, and the one serving requests is rarely the one you tested:
//
//   sudo -u www-data php scripts/check-env.php

require __DIR__ . '/../vendor/autoload.php';

use Keel\Database;
use Keel\Env;
use Keel\Host;

const EXPECTED_PHP_MINOR = '8.4';

// Extensions with a real call site. Deliberately not a wishlist -- every entry we ask for is one
// more thing that can differ between your machine and the server.
const REQUIRED_EXTENSIONS = [
    'pdo_mysql' => 'every model',
    'mbstring'  => 'mb_substr in AdminLog and EmailBlocks',
    'json'      => 'API responses throughout',
    'openssl'   => 'https:// stream wrappers for the mail and SMS providers',
];

// Needed only by parts an application can choose not to use.
const OPTIONAL_EXTENSIONS = [
    'sodium'   => 'Keel\Security\Crypto — encrypts TOTP secrets at rest. Required if you use two-factor.',
    'curl'     => 'stripe/stripe-php. Required if you use Keel\Billing.',
    'gd'       => 'image work. Not used by the framework itself.',
    'fileinfo' => 'MIME sniffing for uploads. Not used by the framework itself.',
];

$isCli = PHP_SAPI === 'cli';
$failures = [];

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures[] = $label;
    }

    printf("[%s] %-30s %s\n", $ok ? ' OK ' : 'FAIL', $label, $detail);
}

// Something worth knowing that isn't wrong enough to fail a deploy.
function warn(string $label, string $detail): void
{
    printf("[ ?? ] %-30s %s\n", $label, $detail);
}

printf("PHP %s (%s)\n\n", PHP_VERSION, PHP_SAPI);

check(
    'PHP version',
    version_compare(PHP_VERSION, EXPECTED_PHP_MINOR, '>='),
    'want ' . EXPECTED_PHP_MINOR . '+, have ' . PHP_VERSION
);

foreach (REQUIRED_EXTENSIONS as $ext => $why) {
    check('ext-' . $ext, extension_loaded($ext), $why);
}

foreach (OPTIONAL_EXTENSIONS as $ext => $why) {
    if (!extension_loaded($ext)) {
        warn('ext-' . $ext, 'not loaded — ' . $why);
    }
}

// The mail and SMS providers do their HTTP over stream wrappers rather than curl.
check(
    'allow_url_fopen',
    (bool) ini_get('allow_url_fopen'),
    'the Mailgun and Twilio providers fetch over stream wrappers'
);

// Not a hard failure: production wants display_errors=Off, but a dev box that hides deprecations
// is exactly how the next version bump ambushes you.
$errorReporting = (int) ini_get('error_reporting');
printf(
    "[ ?? ] %-30s display_errors=%s, deprecations %s\n",
    'error visibility',
    ini_get('display_errors') ? 'On' : 'Off',
    ($errorReporting & E_DEPRECATED) ? 'logged' : 'HIDDEN (next version bump will surprise you)'
);

Env::load(__DIR__ . '/../config/.env');

// ── Database ──────────────────────────────────────────────────────────────────────────────────

$pdo = null;
if (!Database::isConfigured()) {
    warn('database', 'DB_NAME not set — running without a database (fine for a brochure site)');
} else {
    try {
        $pdo = Database::connect();
        check('database', true, $_ENV['DB_NAME'] . '@' . $_ENV['DB_HOST']);
    } catch (\Throwable $e) {
        // Message only, never the exception -- the PDO frame carries DB_PASS in its trace.
        check('database', false, $e->getMessage());
    }
}

// PHP and MySQL must agree on what time it is, or the database silently accumulates a MIXTURE of
// timezones: PHP-written columns (expires_at, locked_until) in one, MySQL-written DEFAULT
// CURRENT_TIMESTAMP columns (created_at) in another. Nothing errors -- the values are just offset
// -- and the damage shows up in whatever compares across the two. Both halves are asserted,
// because either drifting alone reintroduces the bug.
if ($pdo !== null) {
    $phpTz = date_default_timezone_get();
    check(
        'PHP timezone is UTC',
        $phpTz === 'UTC',
        $phpTz === 'UTC' ? 'UTC' : $phpTz . ' — set date.timezone=UTC in php.ini (CLI and the web SAPI have separate files)'
    );

    $skew = strtotime((string) $pdo->query('SELECT NOW()')->fetchColumn()) - time();
    check(
        'MySQL clock matches PHP',
        abs($skew) <= 2,
        $skew === 0 ? 'both UTC' : sprintf('off by %+ds — Keel\Database::connect() should pin it', $skew)
    );
}

// ── Hosts ─────────────────────────────────────────────────────────────────────────────────────
//
// APP_URL and APP_DOMAIN together decide which surface a request reaches. They can disagree, and
// when they do the symptom is a whole surface being unreachable rather than an error.

$appHost = Host::appHost();
$appDomain = Host::appDomain();

if ($appDomain === '') {
    warn('hosts', 'APP_DOMAIN not set — single-host install, everything routes to the app');
} else {
    check(
        'hosts',
        $appHost !== '',
        $appHost !== ''
            ? "app=$appHost, marketing=$appDomain"
            : 'APP_URL is blank or unparseable, so the app host cannot be derived'
    );

    if ($appHost !== '' && $appHost === $appDomain) {
        warn('hosts', "APP_URL and APP_DOMAIN are both $appDomain — the marketing router is unreachable");
    }
}

// ── Storage ───────────────────────────────────────────────────────────────────────────────────
//
// mail and sms are created lazily by the log providers, so an unwritable one stays invisible until
// the first send -- which under Apache is a different user from whoever ran this by hand.

foreach (['mail', 'sms', 'logs'] as $dir) {
    $path = __DIR__ . '/../storage/' . $dir;
    check('storage/' . $dir . ' writable', is_dir($path) && is_writable($path), $path);
}

// ── Upload limits ─────────────────────────────────────────────────────────────────────────────
//
// SAPI-specific: the build serving requests has its own php.ini, so run this as the web user to
// check that one.

$iniBytes = static function (string $key): int {
    $v = trim((string) ini_get($key));
    if ($v === '' || $v === '-1') return $v === '-1' ? -1 : 0;
    $unit = strtolower($v[strlen($v) - 1]);
    $n = (int) $v;
    return match ($unit) {
        'g' => $n * 1024 * 1024 * 1024,
        'm' => $n * 1024 * 1024,
        'k' => $n * 1024,
        default => (int) $v,
    };
};

$umf = $iniBytes('upload_max_filesize');
$pms = $iniBytes('post_max_size');
$mem = $iniBytes('memory_limit');

// post_max_size below upload_max_filesize is the one that catches people out: the upload is
// rejected before any controller runs, and $_FILES is simply empty with no error to read.
check(
    'post_max_size',
    $pms >= $umf,
    ini_get('post_max_size') . ' (must be ≥ upload_max_filesize, ' . ini_get('upload_max_filesize') . ')'
);

if ($mem === -1) {
    warn('memory_limit', 'unlimited (-1) — prefer a finite limit, e.g. 256M');
} else {
    check('memory_limit', $mem >= 128 * 1024 * 1024, ini_get('memory_limit') . ' (≥ 128M recommended)');
}

// ── OPcache ───────────────────────────────────────────────────────────────────────────────────
//
// The biggest free throughput multiplier for the request SAPI: it caches compiled bytecode so each
// request skips re-parsing every .php file. The CLI and web builds have separate php.ini files, so
// the SAPI that serves requests is the one that matters.

$opcacheOn = filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
if ($isCli) {
    // CLI OPcache is off by default (opcache.enable_cli) and that's expected -- report, don't
    // fail, and point at the check that actually matters.
    warn('opcache (cli)', ($opcacheOn ? 'on' : 'off') . ' — CLI build; run as the web user to check the serving SAPI');
} else {
    check('opcache.enable', $opcacheOn, $opcacheOn ? 'on' : 'OFF — serving without bytecode caching wastes most of the CPU per request');
}

if ($opcacheOn) {
    // In production the .php files don't change between deploys, so timestamp revalidation is pure
    // overhead (a stat() per included file per request). 0 is the fast setting; reload PHP on deploy.
    if (filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN)) {
        warn('opcache.validate_timestamps', 'on — fine in dev; set to 0 in production and reload PHP on deploy');
    }
    $ocMemMb = (int) ini_get('opcache.memory_consumption');
    check('opcache.memory_consumption', $ocMemMb >= 128, $ocMemMb . 'M (≥ 128M recommended)');
    $ocFiles = (int) ini_get('opcache.max_accelerated_files');
    check('opcache.max_accelerated_files', $ocFiles >= 10000, $ocFiles . ' (≥ 10000 recommended)');
}

if ($failures !== []) {
    printf("\n%d check(s) failed: %s\n", count($failures), implode(', ', $failures));
    if ($isCli) {
        exit(1);
    }
    http_response_code(500);
    return;
}

print("\nAll checks passed.\n");
