<?php

declare(strict_types=1);

namespace Framework;

use PDO;

// The one place a database connection is opened, so every entry point — the web front controller,
// the migration runner, the cron workers — gets identical settings.
//
// It exists chiefly to pin the session to UTC. PHP is configured for UTC (date.timezone in
// php.ini), but MySQL defaults its time_zone to SYSTEM, which is whatever the host is set to. When
// those disagree the database ends up holding a MIXTURE of timezones, because the app writes
// timestamps two different ways:
//
//   - PHP-written: `date('Y-m-d H:i:s')` into expires_at, locked_until, ... -> PHP's tz
//   - MySQL-written: DEFAULT CURRENT_TIMESTAMP on created_at, queued_at, ... -> MySQL's tz
//
// Both look like plain DATETIME strings afterwards, so nothing complains; the values are simply
// offset from each other. Anything comparing across the two — a month boundary built in PHP tested
// against a MySQL-written created_at, say — is then wrong by the offset, silently, and only for
// rows near the boundary. `SET time_zone = '+00:00'` collapses that whole class of bug.
//
// This is deliberately set per-connection rather than relying on my.cnf: it then holds on any host
// the app is deployed to, including ones we don't control, and can't be undone by a server config
// change. Setting default-time-zone='+00:00' in my.cnf as well is good belt-and-braces, but this
// is what guarantees it. scripts/check-env.php asserts both halves still agree.
class Database
{
    /**
     * Whether this application has a database at all.
     *
     * Not every Keel app does — a brochure or portfolio site gets the router, the container and
     * the view layer and nothing else. The front controller asks this before connecting, so an
     * app with no DB_NAME never opens a socket and never needs MySQL installed to boot.
     */
    public static function isConfigured(): bool
    {
        return ($_ENV['DB_NAME'] ?? '') !== '';
    }

    public static function connect(): PDO
    {
        $pdo = new PDO(
            'mysql:host=' . ($_ENV['DB_HOST'] ?? '127.0.0.1') . ';dbname=' . ($_ENV['DB_NAME'] ?? '') . ';charset=utf8mb4',
            $_ENV['DB_USER'] ?? '',
            $_ENV['DB_PASS'] ?? '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        // Numeric offset rather than 'UTC': naming a zone requires MySQL's timezone tables to have
        // been loaded (mysql_tzinfo_to_sql), which they are not on a stock install.
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;
    }
}
