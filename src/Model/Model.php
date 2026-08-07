<?php

declare(strict_types=1);

namespace Framework\Model;

use PDO;

/**
 * Static active-record base.
 *
 * Every table has both an integer `id` (internal primary key) and a UUID `uid` (public-facing).
 * Use `uid` in URLs and API responses, never `id` — a sequential integer in a URL tells anyone
 * who looks how many rows you have and lets them walk the rest.
 *
 * A subclass declares `$table`, `$fields` and its typed public properties, and overrides
 * `fromRow()` / `serializeField()` when a column needs converting (a backed enum, say).
 *
 * @phpstan-consistent-constructor Rows are hydrated by `new static()` and then populated, so a
 *   subclass must not add required constructor arguments. Do setup in `fromRow()` instead.
 */
abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    protected static array $fields = [];
    protected static array $searchFields = [];
    protected static bool $softDeletes = true;

    private static ?PDO $pdo = null;

    public static function setConnection(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    protected static function connection(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('No database connection. Call Model::setConnection() at bootstrap.');
        }
        return self::$pdo;
    }

    // -------------------------------------------------------------------------
    // Static query API
    // -------------------------------------------------------------------------

    public static function find(int|string $id, bool $withDeleted = false): ?static
    {
        $table = static::$table;
        $pk = static::$primaryKey;

        $sql = "SELECT * FROM `$table` WHERE `$pk` = ?";
        if (static::$softDeletes && !$withDeleted) {
            $sql .= " AND `deleted` = 0";
        }
        $sql .= " LIMIT 1";

        $stmt = static::connection()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? static::fromRow($row) : null;
    }

    public static function findByUid(string $uid, bool $withDeleted = false): ?static
    {
        $table = static::$table;

        $sql = "SELECT * FROM `$table` WHERE `uid` = ?";
        if (static::$softDeletes && !$withDeleted) {
            $sql .= " AND `deleted` = 0";
        }
        $sql .= " LIMIT 1";

        $stmt = static::connection()->prepare($sql);
        $stmt->execute([$uid]);
        $row = $stmt->fetch();

        return $row ? static::fromRow($row) : null;
    }

    protected static function generateUid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function all(bool $withDeleted = false): array
    {
        $table = static::$table;
        $sql = "SELECT * FROM `$table`";
        if (static::$softDeletes && !$withDeleted) {
            $sql .= " WHERE `deleted` = 0";
        }
        $stmt = static::connection()->query($sql);

        return array_map(fn(array $row) => static::fromRow($row), $stmt->fetchAll());
    }

    public static function paginate(int $page, int $perPage, string $search = '', array $conditions = [], bool $withDeleted = false): array
    {
        $table = static::$table;
        $offset = ($page - 1) * $perPage;
        $clauses = [];
        $params = [];

        foreach ($conditions as $col => $val) {
            $clauses[] = "`$col` = ?";
            $params[] = $val;
        }

        if ($search !== '' && !empty(static::$searchFields)) {
            $searchClauses = array_map(fn($f) => "`$f` LIKE ?", static::$searchFields);
            $clauses[] = '(' . implode(' OR ', $searchClauses) . ')';
            foreach (static::$searchFields as $_) {
                $params[] = "%$search%";
            }
        }

        if (static::$softDeletes && !$withDeleted) {
            $clauses[] = "`deleted` = 0";
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $countStmt = static::connection()->prepare("SELECT COUNT(*) FROM `$table` $where");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $dataStmt = static::connection()->prepare("SELECT * FROM `$table` $where LIMIT ? OFFSET ?");
        foreach ($params as $i => $param) {
            $dataStmt->bindValue($i + 1, $param);
        }
        $dataStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
        $dataStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $items = array_map(fn(array $row) => static::fromRow($row), $dataStmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }

    public static function where(array $conditions, bool $withDeleted = false): array
    {
        $table = static::$table;
        $clauses = [];
        $values = [];

        foreach ($conditions as $column => $value) {
            $clauses[] = "`$column` = ?";
            $values[] = $value;
        }

        if (static::$softDeletes && !$withDeleted) {
            $clauses[] = "`deleted` = 0";
        }

        $sql = "SELECT * FROM `$table`" . ($clauses ? ' WHERE ' . implode(' AND ', $clauses) : '');
        $stmt = static::connection()->prepare($sql);
        $stmt->execute($values);

        return array_map(fn(array $row) => static::fromRow($row), $stmt->fetchAll());
    }

    // -------------------------------------------------------------------------
    // Instance API
    // -------------------------------------------------------------------------

    public function save(): static
    {
        $pk = static::$primaryKey;
        $table = static::$table;

        $data = [];
        foreach (static::$fields as $field) {
            $data[$field] = $this->serializeField($field);
        }

        if (empty($this->$pk)) {
            // Auto-generate UID on first insert
            if (property_exists($this, 'uid') && $this->uid === '') {
                $this->uid = static::generateUid();
                $data['uid'] = $this->uid;
            }

            // INSERT
            $columns = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));

            $stmt = static::connection()->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)");
            $stmt->execute(array_values($data));
            $this->$pk = (int) static::connection()->lastInsertId();
        } else {
            // UPDATE
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
            $stmt = static::connection()->prepare("UPDATE `$table` SET $set WHERE `$pk` = ?");
            $stmt->execute([...array_values($data), $this->$pk]);
        }

        return $this;
    }

    public function delete(): bool
    {
        $pk = static::$primaryKey;
        $table = static::$table;

        if (static::$softDeletes) {
            $stmt = static::connection()->prepare("UPDATE `$table` SET `deleted` = 1 WHERE `$pk` = ?");
            $stmt->execute([$this->$pk]);
            return $stmt->rowCount() > 0;
        }

        $stmt = static::connection()->prepare("DELETE FROM `$table` WHERE `$pk` = ?");
        $stmt->execute([$this->$pk]);
        return $stmt->rowCount() > 0;
    }

    public static function destroy(int $id): bool
    {
        $instance = static::find($id);
        return $instance?->delete() ?? false;
    }

    // Clears the soft-delete flag on this row. No-op for models that hard-delete.
    public function restore(): bool
    {
        if (!static::$softDeletes) {
            return false;
        }
        $pk = static::$primaryKey;
        $stmt = static::connection()->prepare("UPDATE `" . static::$table . "` SET `deleted` = 0 WHERE `$pk` = ?");
        $stmt->execute([$this->$pk]);
        return $stmt->rowCount() > 0;
    }

    // -------------------------------------------------------------------------
    // Hydration
    // -------------------------------------------------------------------------

    protected static function fromRow(array $row): static
    {
        $instance = new static();
        $pk = static::$primaryKey;

        $instance->$pk = (int) $row[$pk];

        if (property_exists($instance, 'uid')) {
            $instance->uid = (string) ($row['uid'] ?? '');
        }

        foreach (static::$fields as $field) {
            $instance->$field = $row[$field] ?? $instance->$field;
        }

        return $instance;
    }

    // Override in subclasses that need to transform a property before persistence
    // (e.g. serialising a backed enum to its string value).
    protected function serializeField(string $field): mixed
    {
        return $this->$field;
    }

    public function toArray(): array
    {
        $pk = static::$primaryKey;
        $result = [];

        if (property_exists($this, 'uid')) {
            $result['uid'] = $this->uid;
        } else {
            $result[$pk] = $this->$pk;
        }

        foreach (static::$fields as $field) {
            if ($field === 'uid') continue;
            $result[$field] = $this->serializeField($field);
        }

        return $result;
    }
}
