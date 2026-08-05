# Database migrations

Lightweight, forward-only migration runner. No `down()`/rollback, no schema diffing —
just tracking which files have run and applying new ones in order.

## Running

```bash
php scripts/migrate.php
```

Safe to re-run any time — already-applied files are skipped. Applied filenames are
recorded in the `migrations` table (created automatically on first run).

## Adding a migration

Create a new file here named `YYYYMMDD_HHMMSS_description.php` (full timestamp, not
just a date, so same-day migrations don't collide and always sort chronologically).
It must return a callable:

```php
<?php

declare(strict_types=1);

// One-line description of what this changes and why.

return function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE `components` ADD COLUMN `widget_type` VARCHAR(50) NULL AFTER `type`");
};
```

Returning a closure (rather than plain top-level statements) gives each file its own
variable scope when the runner `require`s many of them in a single process.

## Write migrations that are safe to retry

MySQL DDL (`CREATE`/`ALTER`/`DROP TABLE`) auto-commits and is **not** rolled back if a
later statement in the same file throws — the runner wraps each migration in a
transaction, but that only protects plain DML, not DDL. If a migration fails partway
through, it is *not* recorded as applied, so it will run again on the next `migrate.php`
invocation. Keep one logical schema change per file, and guard with existence checks
(`SHOW TABLES` / `DESCRIBE`), so a retry after a partial failure doesn't error out on
work that already happened.

## Relationship to `schema.sql`

`schema.sql` in the project root is the from-scratch baseline, applied once by
`scripts/init-db.php` on a brand-new database. Everything after that lives here.

Do **not** edit `schema.sql` to change an existing database — nothing re-runs it, so the
change would land on new installs and nowhere else. Add a migration, and update
`schema.sql` alongside it only so a fresh install starts where an upgraded one ends up.
