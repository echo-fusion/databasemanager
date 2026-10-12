<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Connections;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\DatabaseManager\Exceptions\DatabaseException;
use EchoFusion\DatabaseManager\Exceptions\QueryException;
use EchoFusion\DatabaseManager\ParameterExpander;
use Throwable;
use function array_diff_key;

/**
 * Adapter that backs the contract with Doctrine DBAL. Doctrine is optional: install `doctrine/dbal`.
 *
 * It proves the layer is replaceable: everything written against ConnectionInterface
 * (including the QueryBuilder) works unchanged on Doctrine.
 *
 * Config (when used through the DatabaseManager): the usual DBAL connection parameters
 * (`driver` such as pdo_mysql / pdo_sqlite, `host`, `dbname`, `user`, `password`, `path`, ...).
 */
final class DoctrineConnection implements ConnectionInterface
{
    private readonly Connection $connection;

    /**
     * @param Connection|array<string, mixed> $connection a DBAL connection, or the parameters to create one
     */
    public function __construct(Connection|array $connection)
    {
        $this->connection = $connection instanceof Connection
            ? $connection
            : DriverManager::getConnection(array_diff_key($connection, ['class' => true]));
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        [$sql, $params] = ParameterExpander::expand($sql, $params);

        try {
            return $this->connection->fetchAllAssociative($sql, $params);
        } catch (DbalException $e) {
            throw new QueryException($sql, $e);
        }
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        [$sql, $params] = ParameterExpander::expand($sql, $params);

        try {
            $row = $this->connection->fetchAssociative($sql, $params);
        } catch (DbalException $e) {
            throw new QueryException($sql, $e);
        }

        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        [$sql, $params] = ParameterExpander::expand($sql, $params);

        try {
            $value = $this->connection->fetchOne($sql, $params);
        } catch (DbalException $e) {
            throw new QueryException($sql, $e);
        }

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        [$sql, $params] = ParameterExpander::expand($sql, $params);

        try {
            return (int) $this->connection->executeStatement($sql, $params);
        } catch (DbalException $e) {
            throw new QueryException($sql, $e);
        }
    }

    public function lastInsertId(?string $name = null): string
    {
        try {
            return (string) $this->connection->lastInsertId();
        } catch (DbalException $e) {
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
            $this->connection->beginTransaction();
        } catch (DbalException $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    public function commit(): void
    {
        try {
            $this->connection->commit();
        } catch (DbalException $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    public function rollBack(): void
    {
        try {
            $this->connection->rollBack();
        } catch (DbalException $e) {
            throw new DatabaseException($e->getMessage(), 0, $e);
        }
    }

    public function inTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }
}
