<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Tests;

use Doctrine\DBAL\DriverManager;
use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\DatabaseManager\Connections\DoctrineConnection;

/**
 * The very same behaviour tests as for PDO: Doctrine is a drop-in replacement.
 */
final class DoctrineConnectionTest extends ConnectionContractTestCase
{
    protected function createConnection(): ConnectionInterface
    {
        return new DoctrineConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
    }

    public function testAcceptsAnExistingDbalConnection(): void
    {
        $dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection = new DoctrineConnection($dbal);

        $this->assertSame($dbal, $connection->getConnection());
        $this->assertSame(1, $connection->fetchValue('SELECT 1'));
    }

    public function testClassKeyIsNotPassedToDoctrine(): void
    {
        $connection = new DoctrineConnection(['class' => DoctrineConnection::class, 'driver' => 'pdo_sqlite', 'memory' => true]);

        $this->assertSame(1, $connection->fetchValue('SELECT 1'));
    }
}
