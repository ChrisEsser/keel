<?php

declare(strict_types=1);

namespace Framework\Accounts\Model;

use Framework\Model\Model;

// Rows of the platform activity log. Written only through Framework\Accounts\Service\AdminLog (never construct
// one directly — the service is what fills in the actor, the labels and the impersonation flag),
// and read only by the admin activity area.
//
// Append-only: no update path, no delete path, no soft-delete column. An audit trail you can edit
// from inside the app isn't one.
class AdminEventModel extends Model
{
    protected static string $table = 'admin_events';
    protected static bool $softDeletes = false;
    protected static array $fields = [
        'event', 'category', 'summary', 'actor_user_id', 'actor_label', 'org_id', 'org_label',
        'subject_user_id', 'subject_label', 'impersonated', 'ip', 'meta',
    ];

    public int $id = 0;
    public string $event = '';
    public string $category = '';
    public string $summary = '';
    public ?int $actor_user_id = null;
    public string $actor_label = '';
    public ?int $org_id = null;
    public string $org_label = '';
    public ?int $subject_user_id = null;
    public string $subject_label = '';
    public int $impersonated = 0;
    public string $ip = '';
    public ?string $meta = null;
    public string $created_at = '';

    // `created_at` is set by the column default, not by save(), so it isn't in $fields and the base
    // fromRow() never carries it across — and a log whose rows don't know when they happened is
    // useless. Hydrated here instead.
    protected static function fromRow(array $row): static
    {
        $instance = parent::fromRow($row);
        $instance->created_at = (string) ($row['created_at'] ?? '');
        return $instance;
    }

    /**
     * Newest-first page of the log under the admin area's filters.
     *
     * $filters accepts: org_id, subject_user_id, actor_user_id, event, category, from, to, search.
     * `search` is a substring match across the four labels, the summary and the raw `meta` JSON —
     * meta is included deliberately, because it carries the email addresses and domain names a
     * support person actually types in ("bob@example.com", "chrisesser.com") while the display
     * text stays clean.
     *
     * @return array{items: list<static>, total: int}
     */
    public static function search(array $filters, int $page, int $perPage): array
    {
        [$where, $params] = self::buildWhere($filters);
        $pdo = static::connection();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `admin_events` {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM `admin_events` {$where} ORDER BY `id` DESC LIMIT ? OFFSET ?");
        foreach ($params as $i => $p) {
            $stmt->bindValue($i + 1, $p);
        }
        $stmt->bindValue(count($params) + 1, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => array_map(fn(array $row) => static::fromRow($row), $stmt->fetchAll()),
            'total' => $total,
        ];
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        foreach (['org_id', 'subject_user_id', 'actor_user_id'] as $col) {
            if (!empty($filters[$col])) {
                $clauses[] = "`{$col}` = ?";
                $params[] = (int) $filters[$col];
            }
        }
        foreach (['event', 'category'] as $col) {
            if (!empty($filters[$col])) {
                $clauses[] = "`{$col}` = ?";
                $params[] = (string) $filters[$col];
            }
        }
        if (!empty($filters['from'])) {
            $clauses[] = '`created_at` >= ?';
            $params[] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $clauses[] = '`created_at` <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['search'])) {
            $clauses[] = '(`summary` LIKE ? OR `actor_label` LIKE ? OR `subject_label` LIKE ?'
                . ' OR `org_label` LIKE ? OR `meta` LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }

    // Delete rows in one category older than $days, in batches.
    //
    // Batched rather than one DELETE: the first prune after this ships has years of `access` rows
    // to clear, and a single statement over hundreds of thousands of rows holds locks and blocks
    // the writes that produce the log. Each batch is its own short statement, so the worst case is
    // a pause, not an outage. Returns how many rows went.
    //
    // Ordered by `id`, which is monotonic with `created_at` (inserts only, never backdated), so
    // the batch always takes the oldest rows and the index does the work.
    public static function pruneCategory(string $category, int $days, int $batchSize = 5000): int
    {
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $stmt = static::connection()->prepare(
            "DELETE FROM `admin_events` WHERE `category` = ? AND `created_at` < ? ORDER BY `id` LIMIT ?"
        );

        $deleted = 0;
        do {
            $stmt->bindValue(1, $category);
            $stmt->bindValue(2, $cutoff);
            $stmt->bindValue(3, $batchSize, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->rowCount();
            $deleted += $rows;
        } while ($rows === $batchSize);

        return $deleted;
    }

    /** Oldest row still held in a category, for the prune's reporting. Null when it's empty. */
    public static function oldestIn(string $category): ?string
    {
        $stmt = static::connection()->prepare(
            'SELECT MIN(`created_at`) FROM `admin_events` WHERE `category` = ?'
        );
        $stmt->execute([$category]);
        $oldest = $stmt->fetchColumn();
        return $oldest === false || $oldest === null ? null : (string) $oldest;
    }

    /** @return array<string, int> row count per category, for the prune's reporting. */
    public static function countsByCategory(): array
    {
        $rows = static::connection()
            ->query('SELECT `category`, COUNT(*) AS n FROM `admin_events` GROUP BY `category`')
            ->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['category']] = (int) $row['n'];
        }
        return $counts;
    }

    /** Decoded `meta`, or [] when there is none. */
    public function meta(): array
    {
        if ($this->meta === null || $this->meta === '') return [];
        $decoded = json_decode($this->meta, true);
        return is_array($decoded) ? $decoded : [];
    }
}
