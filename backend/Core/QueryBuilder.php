<?php
namespace App\Core;

use PDO;

class QueryBuilder
{
    private PDO $db;
    private string $table;
    private array $selects = ['*'];
    private array $joins = [];
    private array $wheres = [];
    private array $bindings = [];
    private array $orderBy = [];
    private ?int $limitVal = null;
    private ?int $offsetVal = null;
    private int $bindCounter = 0;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function table(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;
        $clone->selects = ['*'];
        $clone->joins = [];
        $clone->wheres = [];
        $clone->bindings = [];
        $clone->orderBy = [];
        $clone->limitVal = null;
        $clone->offsetVal = null;
        return $clone;
    }

    public function select(string ...$columns): self
    {
        $clone = clone $this;
        $clone->selects = $columns;
        return $clone;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $clone = clone $this;
        $clone->joins[] = "LEFT JOIN {$table} ON {$first} {$operator} {$second}";
        return $clone;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $clone = clone $this;
        $clone->joins[] = "JOIN {$table} ON {$first} {$operator} {$second}";
        return $clone;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $key = ':qb_w' . $clone->bindCounter++;
        $clone->wheres[] = "{$column} {$operator} {$key}";
        $clone->bindings[$key] = $value;
        return $clone;
    }

    public function whereRaw(string $sql, array $bindings = []): self
    {
        $clone = clone $this;
        $clone->wheres[] = $sql;
        foreach ($bindings as $k => $v) {
            $clone->bindings[$k] = $v;
        }
        return $clone;
    }

    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = "{$column} IS NULL";
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        // Validate direction is strictly ASC or DESC
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new \InvalidArgumentException("Invalid ORDER BY direction: '{$direction}'. Must be ASC or DESC.");
        }

        // Validate column name: only allow alphanumeric, underscores, and dots (e.g. table.column or column)
        // to prevent SQL injection via malicious column values.
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw new \InvalidArgumentException("Invalid ORDER BY column: '{$column}'.");
        }

        $clone = clone $this;
        $clone->orderBy[] = "{$column} {$direction}";
        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limitVal = $limit;
        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offsetVal = $offset;
        return $clone;
    }

    /** تنفيذ SELECT وإرجاع كل الصفوف */
    public function get(): array
    {
        $stmt = $this->db->prepare($this->buildSelect());
        $this->bindAll($stmt);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** تنفيذ SELECT وإرجاع أول صف فقط */
    public function first(): ?array
    {
        $clone = $this->limit(1);
        $rows = $clone->get();
        return $rows[0] ?? null;
    }

    /** تنفيذ COUNT(*) */
    public function count(): int
    {
        $clone = clone $this;
        $clone->selects = ['COUNT(*) AS cnt'];
        $clone->orderBy = [];
        $clone->limitVal = null;
        $clone->offsetVal = null;
        $stmt = $this->db->prepare($clone->buildSelect());
        $clone->bindAll($stmt);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /** INSERT وإرجاع ID */
    public function insert(array $data): int
    {
        $cols = array_keys($data);
        $placeholders = array_map(fn($c) => ":{$c}", $cols);
        $sql = "INSERT INTO {$this->table} (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    /** UPDATE مع شروط WHERE */
    public function update(array $data): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $col => $val) {
            $key = ":set_{$col}";
            $sets[] = "{$col} = {$key}";
            $params[$key] = $val;
        }
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge($params, $this->bindings));
        return $stmt->rowCount();
    }

    /** DELETE مع شروط WHERE */
    public function delete(): int
    {
        $sql = "DELETE FROM {$this->table}";
        if (!empty($this->wheres)) {
            $sql .= " WHERE " . implode(' AND ', $this->wheres);
        }
        $stmt = $this->db->prepare($sql);
        $this->bindAll($stmt);
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ── Internal ──

    private function buildSelect(): string
    {
        $sql = "SELECT " . implode(', ', $this->selects) . " FROM {$this->table}";
        foreach ($this->joins as $j) $sql .= " {$j}";
        if (!empty($this->wheres)) $sql .= " WHERE " . implode(' AND ', $this->wheres);
        if (!empty($this->orderBy)) $sql .= " ORDER BY " . implode(', ', $this->orderBy);
        if ($this->limitVal !== null) $sql .= " LIMIT {$this->limitVal}";
        if ($this->offsetVal !== null) $sql .= " OFFSET {$this->offsetVal}";
        return $sql;
    }

    private function bindAll(\PDOStatement $stmt): void
    {
        foreach ($this->bindings as $key => $val) {
            $stmt->bindValue($key, $val);
        }
    }
}
