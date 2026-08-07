<?php

declare(strict_types=1);

/**
 * Create the database from nothing and seed the first admin.
 *
 * Run once, on a fresh project:
 *
 *     php scripts/init-db.php
 *     php scripts/init-db.php --force          # allow running against a non-empty database
 *     php scripts/init-db.php --email=me@x.com --password=... --name="Ada Lovelace" --org="Acme"
 *
 * Everything after this goes in scripts/migrations/ and is applied with scripts/migrate.php.
 *
 * Refuses by default if a `users` table already exists. That guard is the whole reason this is
 * safe to leave in a repository: the destructive version of this script is one someone runs on
 * production at 2am thinking it is idempotent.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Keel\Accounts\Model\MembershipModel;
use Keel\Accounts\Model\OrganizationModel;
use Keel\Accounts\Model\Role;
use Keel\Accounts\Model\UserModel;
use Keel\Env;
use Keel\Model\Model;

Env::load(__DIR__ . '/../config/.env');

$opts = getopt('', ['force', 'email:', 'password:', 'name:', 'org:']);
$force = isset($opts['force']);

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$name = $_ENV['DB_NAME'] ?? '';
$user = $_ENV['DB_USER'] ?? '';
$pass = $_ENV['DB_PASS'] ?? '';

if ($name === '') {
    fwrite(STDERR, "DB_NAME is not set in config/.env\n");
    exit(1);
}

function ask(string $prompt, ?string $given = null, bool $hidden = false): string
{
    if ($given !== null && $given !== '') return $given;

    // A non-interactive run (CI, a Dockerfile) has no terminal to prompt at, and blocking forever
    // on a read that will never come is a worse failure than saying so.
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "Not a terminal, and no value given for: $prompt\n");
        exit(1);
    }

    echo $prompt;
    if ($hidden) {
        // Password entry with the echo off. `stty` is POSIX-only; on a system without it the
        // fallback is a visible password, which is better than no password prompt at all.
        @shell_exec('stty -echo 2>/dev/null');
    }
    $value = trim((string) fgets(STDIN));
    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return $value;
}

// ── Create the database if it isn't there ─────────────────────────────────────────────────────

echo "Connecting to {$host}...\n";

try {
    $server = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect: {$e->getMessage()}\n");
    exit(1);
}

// Backticks and an identifier check rather than a bound parameter: CREATE DATABASE cannot take
// one, so the name is validated instead of escaped.
if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    fwrite(STDERR, "DB_NAME must be letters, digits and underscores only.\n");
    exit(1);
}

$server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "Database `{$name}` ready.\n";

$pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET time_zone = '+00:00'");

$existing = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
if ($existing && !$force) {
    fwrite(STDERR, "`{$name}` already has a `users` table. Refusing to run.\n");
    fwrite(STDERR, "Use --force if you are certain, or scripts/migrate.php to apply changes.\n");
    exit(1);
}

// ── Apply the schema ──────────────────────────────────────────────────────────────────────────

$schema = __DIR__ . '/../schema.sql';
if (!is_file($schema)) {
    fwrite(STDERR, "schema.sql not found at {$schema}\n");
    exit(1);
}

echo "Applying schema...\n";
$pdo->exec((string) file_get_contents($schema));

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo '  ' . count($tables) . " tables: " . implode(', ', $tables) . "\n";

// ── Seed the first admin ──────────────────────────────────────────────────────────────────────

Model::setConnection($pdo);

if (UserModel::all() !== []) {
    echo "\nUsers already exist; skipping the admin seed.\n";
    echo "Done.\n";
    exit(0);
}

echo "\nCreate the first admin account.\n";

$fullName = ask('  Name:     ', $opts['name'] ?? null);
$email    = ask('  Email:    ', $opts['email'] ?? null);
$password = ask('  Password: ', $opts['password'] ?? null, hidden: true);
$orgName  = ask('  Org name: ', $opts['org'] ?? null);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That is not a valid email address.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

$parts = preg_split('/\s+/', trim($fullName), 2) ?: [''];

$admin = new UserModel();
$admin->first_name = $parts[0];
$admin->last_name  = $parts[1] ?? '';
$admin->email      = $email;
$admin->password   = password_hash($password, PASSWORD_DEFAULT);
$admin->is_admin   = 1;
$admin->save();

$org = new OrganizationModel();
$org->name  = $orgName;
$org->email = $email;
$org->save();

// The admin's own organization, so they land somewhere real after signing in. Being platform
// staff and being a member of an organization are separate things — this seeds both because a
// first run with neither is a sign-in that goes nowhere.
$membership = new MembershipModel();
$membership->user_id = $admin->id;
$membership->org_id  = $org->id;
$membership->role    = Role::Owner;
$membership->save();

echo "\nDone.\n";
echo "  Admin:        {$admin->email}\n";
echo "  Organization: {$org->displayName()}\n";
echo "\nStart the app with:  php -S localhost:8000 -t public\n";
