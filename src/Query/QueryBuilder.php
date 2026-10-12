<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Query;

use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\DatabaseManager\Connections\PdoConnection;
use EchoFusion\DatabaseManager\Exceptions\QueryBuilderException;
use function array_fill;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function func_num_args;
use function implode;
use function in_array;
use function mb_strtolower;
use function mb_strtoupper;
use function preg_match;
use function sprintf;
use function trim;

/**
 * A small fluent SQL builder that runs through any ConnectionInterface.
 *
 * Table and column names are validated (letters, digits, underscores and dots) and
 * quoted; values are always bound as `?` parameters. Use {@see whereRaw()} and
 * {@see selectRaw()} as escape hatches; their SQL is used as written.
 *
 * <code>
 * $users = QueryBuilder::table($connection, 'users')
 *     ->select('id', 'name')
 *     ->where('active', 1)
 *     ->where('age', '>=', 18)
 *     ->orderBy('name')
 *     ->limit(10)
 *     ->get();
 * </code>
 */
final class QueryBuilder
{
    private const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'like', 'not like'];

    /**
     * @var list<string>
     */
    private array $columns = [];

    private bool $rawColumns = false;

    /**
     * @var list<array{0: string, 1: string}> boolean ("AND"/"OR") and SQL of each condition
     */
    private array $wheres = [];

    /**
     * @var list<mixed>
     */
    private array $bindings = [];

    /**
     * @var list<string>
     */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
        private readonly string $quote = '',
    ) {
        $this->identifier($table);
    }

    /**
     * Start a query on a table; quotes identifiers the way the connection's SQL dialect needs.
     */
    public static function table(ConnectionInterface $connection, string $table): self
    {
        return new self($connection, $table, $connection instanceof PdoConnection ? $connection->identifierQuote() : '');
    }

    public function select(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->columnWithAlias($column); // validate now, not when the SQL is built
        }

        $this->columns = array_values($columns);
        $this->rawColumns = false;

        return $this;
    }

    /**
     * Select with raw SQL, e.g. `selectRaw('COUNT(*) AS total')`. Not escaped.
     */
    public function selectRaw(string $expression): self
    {
        $this->columns = [$expression];
        $this->rawColumns = true;

        return $this;
    }

    /**
     * `where('a', 1)` means `a = 1`; `where('a', '>', 1)` uses the operator.
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        return $this->addComparison('AND', $column, $operatorOrValue, $value, func_num_args());
    }

    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        return $this->addComparison('OR', $column, $operatorOrValue, $value, func_num_args());
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->addIn('AND', $column, $values, false);
    }

    /**
     * @param list<mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addIn('AND', $column, $values, true);
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = ['AND', $this->identifier($column) . ' IS NULL'];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = ['AND', $this->identifier($column) . ' IS NOT NULL'];

        return $this;
    }

    /**
     * Add raw SQL with `?` placeholders to the WHERE clause. Not escaped.
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['AND', '(' . $sql . ')'];
        $this->bindings = array_merge($this->bindings, array_values($bindings));

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $direction = mb_strtolower($direction);
        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new QueryBuilderException(sprintf('Order direction must be "asc" or "desc", "%s" given.', $direction));
        }

        $this->orders[] = $this->identifier($column) . ' ' . mb_strtoupper($direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new QueryBuilderException('The limit cannot be negative.');
        }
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new QueryBuilderException('The offset cannot be negative.');
        }
        $this->offset = $offset;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        return $this->connection->fetchAll($this->toSql(), $this->getBindings());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->connection->fetchOne($this->limitedToOne()->toSql(), $this->getBindings());
    }

    /**
     * The first column of the first row.
     */
    public function value(string $column): mixed
    {
        return $this->connection->fetchValue($this->limitedToOne()->select($column)->toSql(), $this->getBindings());
    }

    public function count(): int
    {
        $query = clone $this;
        $query->orders = [];
        $query->limit = $query->offset = null;

        return (int) $this->connection->fetchValue($query->selectRaw('COUNT(*)')->toSql(), $query->getBindings());
    }

    public function exists(): bool
    {
        return $this->limitedToOne()->selectRaw('1')->connectionValue() !== null;
    }

    /**
     * @param array<string, mixed> $values column => value
     *
     * @return int affected rows
     */
    public function insert(array $values): int
    {
        [$sql, $bindings] = $this->insertStatement($values);

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * @param array<string, mixed> $values column => value
     */
    public function insertGetId(array $values, ?string $sequence = null): string
    {
        [$sql, $bindings] = $this->insertStatement($values);
        $this->connection->execute($sql, $bindings);

        return $this->connection->lastInsertId($sequence);
    }

    /**
     * @param array<string, mixed> $values column => new value
     *
     * @return int affected rows
     *
     * @throws QueryBuilderException when there is no WHERE clause
     */
    public function update(array $values): int
    {
        $this->assertHasWhere('update');
        if ($values === []) {
            throw new QueryBuilderException('There is nothing to update.');
        }

        $sets = array_map(fn (string $column): string => $this->identifier($column) . ' = ?', array_keys($values));

        return $this->connection->execute(
            sprintf('UPDATE %s SET %s%s', $this->identifier($this->table), implode(', ', $sets), $this->whereSql()),
            array_merge(array_values($values), $this->bindings),
        );
    }

    /**
     * @return int affected rows
     *
     * @throws QueryBuilderException when there is no WHERE clause
     */
    public function delete(): int
    {
        $this->assertHasWhere('delete');

        return $this->connection->execute(
            sprintf('DELETE FROM %s%s', $this->identifier($this->table), $this->whereSql()),
            $this->bindings,
        );
    }

    public function toSql(): string
    {
        $sql = sprintf(
            'SELECT %s FROM %s%s',
            $this->columnsSql(),
            $this->identifier($this->table),
            $this->whereSql(),
        );

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ($this->limit === null ? ' LIMIT -1' : '') . ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    private function limitedToOne(): self
    {
        $query = clone $this;
        $query->limit = 1;

        return $query;
    }

    private function connectionValue(): mixed
    {
        return $this->connection->fetchValue($this->toSql(), $this->getBindings());
    }

    private function addComparison(string $boolean, string $column, mixed $operatorOrValue, mixed $value, int $argumentCount): self
    {
        if ($argumentCount === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            $operator = mb_strtolower(trim((string) $operatorOrValue));
            if (!in_array($operator, self::OPERATORS, true)) {
                throw new QueryBuilderException(sprintf('Unsupported operator "%s".', $operator));
            }
        }

        if ($value === null) {
            if ($operator !== '=' && $operator !== '!=' && $operator !== '<>') {
                throw new QueryBuilderException('NULL can only be compared with =, != or <>; use whereNull() / whereNotNull().');
            }
            $this->wheres[] = [$boolean, $this->identifier($column) . ($operator === '=' ? ' IS NULL' : ' IS NOT NULL')];

            return $this;
        }

        $this->wheres[] = [$boolean, sprintf('%s %s ?', $this->identifier($column), mb_strtoupper($operator))];
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    private function addIn(string $boolean, string $column, array $values, bool $negate): self
    {
        if ($values === []) {
            // IN () is a syntax error in most databases: an empty list matches nothing (NOT IN: everything)
            $this->wheres[] = [$boolean, $negate ? '1 = 1' : '1 = 0'];

            return $this;
        }

        $this->wheres[] = [$boolean, sprintf('%s %s (%s)', $this->identifier($column), $negate ? 'NOT IN' : 'IN', implode(', ', array_fill(0, count($values), '?')))];
        $this->bindings = array_merge($this->bindings, array_values($values));

        return $this;
    }

    private function columnsSql(): string
    {
        if ($this->columns === []) {
            return '*';
        }
        if ($this->rawColumns) {
            return $this->columns[0];
        }

        return implode(', ', array_map(fn (string $column): string => $this->columnWithAlias($column), $this->columns));
    }

    private function columnWithAlias(string $column): string
    {
        if (preg_match('/^(.+?)\s+as\s+([A-Za-z_][A-Za-z0-9_]*)$/i', trim($column), $m) === 1) {
            return $this->identifier($m[1]) . ' AS ' . $this->quote . $m[2] . $this->quote;
        }

        return $this->identifier($column);
    }

    private function whereSql(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        $sql = '';
        foreach ($this->wheres as $index => [$boolean, $condition]) {
            $sql .= $index === 0 ? $condition : ' ' . $boolean . ' ' . $condition;
        }

        return ' WHERE ' . $sql;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function insertStatement(array $values): array
    {
        if ($values === []) {
            throw new QueryBuilderException('There is nothing to insert.');
        }

        $columns = array_map(fn (string $column): string => $this->identifier($column), array_keys($values));

        return [
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->identifier($this->table), implode(', ', $columns), implode(', ', array_fill(0, count($values), '?'))),
            array_values($values),
        ];
    }

    private function assertHasWhere(string $action): void
    {
        if ($this->wheres === []) {
            throw new QueryBuilderException(sprintf('Refusing to %s without a WHERE clause; add one, or use whereRaw(\'1 = 1\') if you really mean every row.', $action));
        }
    }

    /**
     * Validate and quote `name`, `table.name` or `table.*`.
     */
    private function identifier(string $name): string
    {
        if ($name === '*') {
            return '*';
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.([A-Za-z_][A-Za-z0-9_]*|\*))*$/', $name) !== 1) {
            throw new QueryBuilderException(sprintf('"%s" is not a valid table or column name.', $name));
        }

        return implode('.', array_map(
            fn (string $part): string => $part === '*' ? '*' : $this->quote . $part . $this->quote,
            explode('.', $name),
        ));
    }
}
