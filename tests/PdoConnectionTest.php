<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Tests;

use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\DatabaseManager\Connections\PdoConnection;
use EchoFusion\DatabaseManager\Exceptions\DatabaseException;
use EchoFusion\DatabaseManager\Exceptions\InvalidConfigException;
use PDO;
use PDOException;

final class PdoConnectionTest extends ConnectionContractTestCase
{
    protected function createConnection(): ConnectionInterface
    {
        return new PdoConnection(['driver' => 'sqlite', 'path' => ':memory:']);
    }

    public function testConnectionIsLazy(): void
    {
        // a path that cannot be opened only fails when the first query runs
        $connection = new PdoConnection(['driver' => 'sqlite', 'path' => '/nonexistent-dir/db.sqlite']);

        $this->expectException(PDOException::class);
        $connection->getPdo();
    }

    public function testFromPdoWrapsAnExistingConnection(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $connection = PdoConnection::fromPdo($pdo);

        $this->assertSame($pdo, $connection->getPdo());
        $this->assertSame('"', $connection->identifierQuote());
        $this->assertSame(1, $connection->fetchValue('SELECT 1'));
    }

    public function testIdentifierQuoteDependsOnTheDriver(): void
    {
        $this->assertSame('`', (new PdoConnection(['driver' => 'mysql']))->identifierQuote());
        $this->assertSame('"', (new PdoConnection(['driver' => 'pgsql']))->identifierQuote());
    }

    public function testCustomOptionsAreMerged(): void
    {
        $connection = new PdoConnection(['driver' => 'sqlite', 'options' => [PDO::ATTR_CASE => PDO::CASE_UPPER]]);

        $this->assertSame(PDO::CASE_UPPER, $connection->getPdo()->getAttribute(PDO::ATTR_CASE));
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $connection->getPdo()->getAttribute(PDO::ATTR_ERRMODE));
    }

    /**
     * @dataProvider dsns
     *
     * @param array<string, mixed> $config
     */
    public function testDsn(array $config, string $expected): void
    {
        $this->assertSame($expected, PdoConnection::dsn($config));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public function dsns(): array
    {
        return [
            'mysql' => [['driver' => 'mysql', 'host' => 'db', 'port' => 3307, 'dbname' => 'app'], 'mysql:host=db;port=3307;dbname=app;charset=utf8mb4'],
            'mysql charset' => [['driver' => 'mysql', 'dbname' => 'app', 'charset' => 'latin1'], 'mysql:host=localhost;dbname=app;charset=latin1'],
            'pgsql' => [['driver' => 'pgsql', 'host' => 'pg', 'dbname' => 'app'], 'pgsql:host=pg;dbname=app'],
            'sqlite file' => [['driver' => 'sqlite', 'path' => '/tmp/x.sqlite'], 'sqlite:/tmp/x.sqlite'],
            'sqlite memory' => [['driver' => 'sqlite'], 'sqlite::memory:'],
            'explicit dsn' => [['driver' => 'mysql', 'dsn' => 'mysql:unix_socket=/tmp/s'], 'mysql:unix_socket=/tmp/s'],
        ];
    }

    public function testUnsupportedDriver(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Unsupported PDO driver "oracle"');

        PdoConnection::dsn(['driver' => 'oracle']);
    }

    public function testArraysCannotBeBoundToPositionalParameters(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('named parameter');

        $this->db->fetchAll('SELECT * FROM users WHERE id IN (?)', [[1, 2]]);
    }
}
