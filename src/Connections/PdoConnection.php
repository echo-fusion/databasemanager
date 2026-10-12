<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Connections;

use Closure;
use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\DatabaseManager\Exceptions\DatabaseException;
use EchoFusion\DatabaseManager\Exceptions\InvalidConfigException;
use EchoFusion\DatabaseManager\Exceptions\QueryException;
use EchoFusion\DatabaseManager\ParameterExpander;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_resource;
use function is_string;
use function sprintf;

/**
 * A connection on top of PDO.
 *
 * - The PDO object is created on first use, so building the manager costs nothing.
 * - Values are always bound, with a PDO type matching the PHP type.
 * - Nested transactions use savepoints: an inner failure rolls back only the inner part.
 *
 * Config keys: `driver` (mysql, pgsql, sqlite), `host`, `port`, `dbname`, `user`, `password`,
 * `charset`, `path` (sqlite file or ":memory:"), `dsn` (use instead of the parts above),
 * `options` (extra PDO attributes).
 */
final class PdoConnection implements ConnectionInterface
{
    private ?PDO $pdo = null;

    /**
     * @var Closure(): PDO
     */
    private Closure $factory;

    private int $level = 0;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
        $this->factory = function () use ($config): PDO {
            $options = $config['options'] ?? [];
            $pdo = new PDO(
                self::dsn($config),
                isset($config['user']) ? (string) $config['user'] : null,
                isset($config['password']) ? (string) $config['password'] : null,
                (is_array($options) ? $options : []) + [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );

            return $pdo;
        };
    }

    /**
     * Wrap a PDO object you already have.
     */
    public static function fromPdo(PDO $pdo): self
    {
        $connection = new self(['driver' => (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)]);
        $connection->pdo = $pdo;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $connection;
    }

    /**
     * Build the PDO DSN from the configuration.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigException
     */
    public static function dsn(array $config): string
    {
        if (isset($config['dsn']) && is_string($config['dsn']) && $config['dsn'] !== '') {
            return $config['dsn'];
        }

        $driver = (string) ($config['driver'] ?? '');

        if ($driver === 'sqlite') {
            return 'sqlite:' . (string) ($config['path'] ?? ':memory:');
        }

        if ($driver !== 'mysql' && $driver !== 'pgsql') {
            throw new InvalidConfigException(sprintf('Unsupported PDO driver "%s"; use mysql, pgsql, sqlite, or set "dsn".', $driver));
        }

        $parts = ['host' => $config['host'] ?? 'localhost', 'port' => $config['port'] ?? null, 'dbname' => $config['dbname'] ?? null];
        if ($driver === 'mysql') {
            $parts['charset'] = $config['charset'] ?? 'utf8mb4';
        }

        $pairs = [];
        foreach ($parts as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = $key . '=' . (string) $value;
            }
        }

        return $driver . ':' . implode(';', $pairs);
    }

    /**
     * The character that quotes identifiers in this driver's SQL.
     */
    public function identifierQuote(): string
    {
        return $this->driverName() === 'mysql' ? '`' : '"';
    }

    public function getPdo(): PDO
    {
        return $this->pdo ??= ($this->factory)();
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);

        return $row === false ? null : $row[0];
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function lastInsertId(?string $name = null): string
    {
        try {
            return (string) $this->getPdo()->lastInsertId($name);
        } catch (PDOException $e) {
            throw new QueryException('lastInsertId', $e);
        }
    }

    public function transactional(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        $this->commit();

        return $result;
    }

    public function beginTransaction(): void
    {
        try {
            if ($this->level === 0) {
                $this->getPdo()->beginTransaction();
            } else {
                $this->getPdo()->exec(sprintf('SAVEPOINT ef_sp_%d', $this->level));
            }
        } catch (PDOException $e) {
            throw new QueryException('BEGIN', $e);
        }

        $this->level++;
    }

    public function commit(): void
    {
        $this->assertInTransaction();

        try {
            if ($this->level === 1) {
                $this->getPdo()->commit();
            } else {
                $this->getPdo()->exec(sprintf('RELEASE SAVEPOINT ef_sp_%d', $this->level - 1));
            }
        } catch (PDOException $e) {
            throw new QueryException('COMMIT', $e);
        }

        $this->level--;
    }

    public function rollBack(): void
    {
        $this->assertInTransaction();

        try {
            if ($this->level === 1) {
                $this->getPdo()->rollBack();
            } else {
                $this->getPdo()->exec(sprintf('ROLLBACK TO SAVEPOINT ef_sp_%d', $this->level - 1));
            }
        } catch (PDOException $e) {
            throw new QueryException('ROLLBACK', $e);
        }

        $this->level--;
    }

    public function inTransaction(): bool
    {
        return $this->level > 0;
    }

    /**
     * @param array<array-key, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        [$sql, $params] = ParameterExpander::expand($sql, $params);

        try {
            $statement = $this->getPdo()->prepare($sql);
            foreach ($params as $key => $value) {
                $statement->bindValue(is_int($key) ? $key + 1 : ':' . $key, self::scalar($value), self::type($value));
            }
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            throw new QueryException($sql, $e);
        }
    }

    private static function scalar(mixed $value): mixed
    {
        return is_bool($value) ? (int) $value : $value;
    }

    private static function type(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value), is_int($value) => PDO::PARAM_INT,
            is_resource($value) => PDO::PARAM_LOB,
            default => PDO::PARAM_STR,
        };
    }

    private function driverName(): string
    {
        $driver = $this->config['driver'] ?? null;

        return is_string($driver) ? $driver : (string) $this->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function assertInTransaction(): void
    {
        if ($this->level === 0) {
            throw new DatabaseException('There is no active transaction.');
        }
    }
}
