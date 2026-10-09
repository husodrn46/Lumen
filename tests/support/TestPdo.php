<?php
declare(strict_types=1);

/** SQL'i yürütmez. Kontrol akışı ve PDO sonuç sözleşmesi için test double. */
final class TestPdo extends PDO
{
    public array $events = [];
    public int $errorMode = PDO::ERRMODE_EXCEPTION;
    public bool $transaction = false;
    public array $transactions = [];
    public function __construct(public Closure $handler, private ?Closure $txHook = null) {}
    public function getAttribute(int $attribute): mixed
    {
        if($attribute===PDO::ATTR_ERRMODE){return $this->errorMode;}
        throw new LogicException('Unsupported synthetic PDO attribute');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TestStatement($this, $query);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $s = $this->prepare($query);
        $s->execute();
        return $s;
    }
    public function exec(string $statement): int|false
    {
        $this->run($statement, []);
        return 0;
    }
    public function run(string $sql, array $params): array
    {
        $this->events[] = ['sql' => $sql, 'params' => $params, 'transaction' => $this->transaction];
        return ($this->handler)($sql, $params);
    }
    public function beginTransaction(): bool { if ($this->txHook) { ($this->txHook)('begin'); } $this->transactions[] = 'begin'; $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { if ($this->txHook) { ($this->txHook)('before_commit'); } $this->transactions[] = 'commit'; $this->transaction = false; if ($this->txHook) { ($this->txHook)('commit'); } return true; }
    public function rollBack(): bool { if ($this->txHook) { ($this->txHook)('rollback'); } $this->transactions[] = 'rollback'; $this->transaction = false; return true; }
}

final class TestStatement extends PDOStatement
{
    private array $params = [];
    private array $sets = [];
    private int $set = 0;
    private int $row = 0;
    private int $affected = 0;
    public bool $closed = false;
    public function __construct(private TestPdo $pdo, private string $sql) {}
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->params[$param] = $value;
        return true;
    }
    public function execute(?array $params = null): bool
    {
        $r = $this->pdo->run($this->sql, $params ?? $this->params);
        $this->sets = $r['sets'] ?? [$r['rows'] ?? []];
        $this->affected = $r['affected'] ?? 0;
        $this->set = $this->row = 0;
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $orientation = PDO::FETCH_ORI_NEXT, int $offset = 0): mixed
    {
        return $this->sets[$this->set][$this->row++] ?? false;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return $row === false ? false : (array_values($row)[$column] ?? false);
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = array_slice($this->sets[$this->set] ?? [], $this->row);
        $this->row += count($rows);
        return $rows;
    }
    public function columnCount(): int { return count($this->sets[$this->set][0] ?? []); }
    public function nextRowset(): bool
    {
        $this->row = 0;
        return ++$this->set < count($this->sets);
    }
    public function closeCursor(): bool { $this->closed = true; return true; }
    public function rowCount(): int { return $this->affected; }
}
