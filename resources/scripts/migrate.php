<?php

declare(strict_types=1);

// Lightweight forward-only migration runner. Applies any not-yet-applied file in
// scripts/migrations/ (in filename order) and records it in the `migrations` table.
// No down()/rollback, no schema diffing -- see scripts/migrations/README.md.
//
// The from-scratch schema is scripts/../schema.sql, applied once by init-db.php. This runner is
// for everything after that.

require __DIR__ . '/../vendor/autoload.php';

use Keel\Database;
use Keel\Env;

Env::load(__DIR__ . '/../config/.env');

$pdo = Database::connect();

$pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename`   VARCHAR(255) NOT NULL,
    `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$applied = array_flip(array_column(
    $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_NUM),
    0
));

$files = glob(__DIR__ . '/migrations/*.php') ?: [];
sort($files);

$ran = 0;
foreach ($files as $path) {
    $filename = basename($path);
    if (isset($applied[$filename])) {
        continue;
    }

    echo "Applying {$filename}...\n";
    $migrate = require $path;

    if (!is_callable($migrate)) {
        fwrite(STDERR, "{$filename} must return a callable: function (PDO \$pdo): void\n");
        exit(1);
    }

    // No explicit transaction wrapping here: MySQL DDL (CREATE/ALTER/DROP TABLE) implicitly
    // commits and isn't rolled back by InnoDB transactions, so wrapping one in
    // beginTransaction()/commit() doesn't add safety -- it only causes the later commit()
    // to fail with "There is no active transaction" once the DDL has already silently ended
    // it, which would misreport an already-applied migration as failed. A DDL failure
    // partway through a file can leave schema partially applied; the file is NOT recorded
    // as applied in that case, so a corrected re-run will retry it -- write migrations with
    // existence guards (SHOW TABLES/DESCRIBE, as the existing scripts/migrate-*.php scripts
    // already do) so they're safe to retry.
    try {
        $migrate($pdo);
        $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $stmt->execute([$filename]);
    } catch (\Throwable $e) {
        fwrite(STDERR, "Migration {$filename} failed: {$e->getMessage()}\n");
        exit(1);
    }

    echo "  applied.\n";
    $ran++;
}

echo $ran === 0 ? "Nothing to migrate.\n" : "Applied {$ran} migration(s).\n";
