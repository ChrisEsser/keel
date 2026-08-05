<?php

declare(strict_types=1);

namespace Keel\Accounts\Model;

use Keel\Model\Model;

// Sliding-window attempt counter behind the public-form rate limits (see the
// create_rate_limit_hits migration for the schema rationale). Extends Model only for the shared
// connection — this is an append-only log with no uid/soft-delete, so everything here is a
// hand-written prepared statement.
//
// Callers build opaque bucket keys through the helpers below and then follow one shape:
//
//     if (RateLimitModel::tooMany($bucket, 5, 3600)) { ...refuse... }
//     RateLimitModel::hit($bucket);
//
// Check before recording, so a refused request doesn't extend its own lockout — otherwise a bot
// hammering the endpoint would keep pushing the window forward and lock a legitimate user out
// indefinitely.
class RateLimitModel extends Model
{
    protected static string $table = 'rate_limit_hits';
    protected static array $fields = [];
    protected static bool $softDeletes = false;

    // An IP-scoped bucket. $action names the limit ('login', 'pwreset', ...). Returns '' for an
    // empty IP so callers can skip limiting rather than lump every unknown-IP request together.
    public static function ipBucket(string $action, string $ip): string
    {
        return $ip === '' ? '' : $action . ':ip:' . $ip;
    }

    // An email-scoped bucket. The address is hashed — this table must not become a list of
    // contactable addresses. Case-folded first so Foo@ and foo@ share a bucket.
    public static function emailBucket(string $action, string $email): string
    {
        $email = strtolower(trim($email));
        return $email === '' ? '' : $action . ':email:' . hash('sha256', $email);
    }

    // An organization-scoped bucket, for limits that belong to an account rather than to whoever
    // is signed in — a whole team shares the org's allowance, and it survives a member switching
    // IP or a second member picking up where the first left off. Used by the design importer,
    // where the org bucket (not the IP one) is the actual brake on bulk fetching.
    public static function orgBucket(string $action, string $orgUid): string
    {
        $orgUid = trim($orgUid);
        return $orgUid === '' ? '' : $action . ':org:' . $orgUid;
    }

    // A user-scoped bucket, for limits on a surface where the organization is optional but the
    // signed-in user never is -- voice transcription is shared by surfaces that don't all know
    // their org. Prefer orgBucket() wherever an org is available: a limit that follows the person
    // rather than the account is escapable by inviting a second person to the same workspace.
    public static function userBucket(string $action, string $userUid): string
    {
        $userUid = trim($userUid);
        return $userUid === '' ? '' : $action . ':user:' . $userUid;
    }

    // True when the bucket has already reached $max hits inside the trailing $windowSeconds.
    // An empty bucket key is never limited (see ipBucket()).
    public static function tooMany(string $bucket, int $max, int $windowSeconds): bool
    {
        if ($bucket === '') return false;

        $stmt = static::connection()->prepare(
            'SELECT COUNT(*) FROM `rate_limit_hits`
             WHERE `bucket` = ? AND `created_at` >= (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([$bucket, $windowSeconds]);

        return (int) $stmt->fetchColumn() >= $max;
    }

    // How many hits the bucket holds inside the trailing window. For limits shown in the UI —
    // tooMany() answers "may I?", this answers "how many left?".
    public static function countRecent(string $bucket, int $windowSeconds): int
    {
        if ($bucket === '') return 0;

        $stmt = static::connection()->prepare(
            'SELECT COUNT(*) FROM `rate_limit_hits`
             WHERE `bucket` = ? AND `created_at` >= (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([$bucket, $windowSeconds]);

        return (int) $stmt->fetchColumn();
    }

    // Unix time at which the bucket next drops below its cap: the oldest hit in the window, plus
    // the window. Null when the bucket is empty. Lets the UI say "in about 4 hours" instead of
    // leaving the user guessing — the window slides, so there is no fixed daily reset to quote.
    public static function nextFreeAt(string $bucket, int $windowSeconds): ?int
    {
        if ($bucket === '') return null;

        $stmt = static::connection()->prepare(
            'SELECT MIN(`created_at`) FROM `rate_limit_hits`
             WHERE `bucket` = ? AND `created_at` >= (NOW() - INTERVAL ? SECOND)'
        );
        $stmt->execute([$bucket, $windowSeconds]);
        $oldest = $stmt->fetchColumn();

        return $oldest ? strtotime((string) $oldest) + $windowSeconds : null;
    }

    public static function hit(string $bucket): void
    {
        if ($bucket === '') return;

        $stmt = static::connection()->prepare('INSERT INTO `rate_limit_hits` (`bucket`) VALUES (?)');
        $stmt->execute([$bucket]);

        // Retention without cron: roughly one insert in 100 pays for a bounded prune. Nothing here
        // uses a window longer than a day, so anything older than 2 days is dead weight.
        if (random_int(1, 100) === 1) {
            static::connection()->exec(
                'DELETE FROM `rate_limit_hits`
                 WHERE `created_at` < (NOW() - INTERVAL 2 DAY) LIMIT 5000'
            );
        }
    }

    // Clears a bucket after a genuine success, so one good login doesn't leave a user carrying
    // the failures that preceded it.
    public static function clear(string $bucket): void
    {
        if ($bucket === '') return;

        $stmt = static::connection()->prepare('DELETE FROM `rate_limit_hits` WHERE `bucket` = ?');
        $stmt->execute([$bucket]);
    }
}
