<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Tests;

use EchoFusion\Contracts\ConfigManager\ConfigInterface;
use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\Contracts\DatabaseManager\DatabaseManagerInterface;
use EchoFusion\DatabaseManager\Connections\DoctrineConnection;
use EchoFusion\DatabaseManager\Connections\PdoConnection;
use EchoFusion\DatabaseManager\DatabaseManager;
use EchoFusion\DatabaseManager\Exceptions\ConnectionNotFoundException;
use EchoFusion\DatabaseManager\Exceptions\InvalidConfigException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DatabaseManagerTest extends TestCase
{
    public function testNamedLazyReusedConnections(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'main',
            'connections' => [
                'main' => ['driver' => 'sqlite'],
                'other' => ['driver' => 'sqlite'],
            ],
        ]);

        $this->assertInstanceOf(DatabaseManagerInterface::class, $manager);
        $this->assertSame('main', $manager->getDefaultConnectionName());
        $this->assertTrue($manager->has('other'));
        $this->assertFalse($manager->has('nope'));
        $this->assertInstanceOf(PdoConnection::class, $manager->connection());
        $this->assertSame($manager->connection(), $manager->connection('main'));
        $this->assertNotSame($manager->connection('main'), $manager->connection('other'));
    }

    public function testDefaultsToTheFirstConnection(): void
    {
        $manager = new DatabaseManager(['connections' => ['first' => ['driver' => 'sqlite'], 'second' => ['driver' => 'sqlite']]]);

        $this->assertSame('first', $manager->getDefaultConnectionName());
    }

    public function testConfigObjectIsAccepted(): void
    {
        $config = new class() implements ConfigInterface {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function has(string $key): bool
            {
                return true;
            }

            public function all(): array
            {
                return ['connections' => ['main' => ['driver' => 'sqlite']]];
            }
        };

        $this->assertSame(1, (new DatabaseManager($config))->connection()->fetchValue('SELECT 1'));
    }

    public function testImplementationIsSwappedThroughConfig(): void
    {
        $manager = DatabaseManager::fromConfig([
            'default' => 'pdo',
            'connections' => [
                'pdo' => ['driver' => 'sqlite'],
                'doctrine' => ['class' => DoctrineConnection::class, 'driver' => 'pdo_sqlite', 'memory' => true],
            ],
        ]);

        $this->assertInstanceOf(PdoConnection::class, $manager->connection('pdo'));
        $this->assertInstanceOf(DoctrineConnection::class, $manager->connection('doctrine'));

        // the code that uses a connection does not care which one it got
        foreach (['pdo', 'doctrine'] as $name) {
            $this->assertSame(2, $this->addOne($manager->connection($name)));
        }
    }

    public function testTableShortcut(): void
    {
        $manager = new DatabaseManager(['connections' => ['main' => ['driver' => 'sqlite']]]);
        $manager->connection()->execute('CREATE TABLE t (id INTEGER)');
        $manager->table('t')->insert(['id' => 7]);

        $this->assertSame(7, $manager->table('t', 'main')->value('id'));
    }

    public function testReconfigureDropsExistingConnections(): void
    {
        $manager = new DatabaseManager(['connections' => ['main' => ['driver' => 'sqlite']]]);
        $first = $manager->connection();

        $manager->configure(['connections' => ['main' => ['driver' => 'sqlite']]]);

        $this->assertNotSame($first, $manager->connection());
    }

    public function testUnknownConnection(): void
    {
        $manager = new DatabaseManager(['connections' => ['main' => ['driver' => 'sqlite']]]);

        $this->expectException(ConnectionNotFoundException::class);
        $this->expectExceptionMessage('Database connection "nope" is not defined.');
        $manager->connection('nope');
    }

    public function testNothingConfigured(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('No database connections are configured.');

        (new DatabaseManager())->connection();
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<string, mixed> $config
     */
    public function testInvalidConfig(array $config, string $message, bool $failsOnUse = false): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        $manager = new DatabaseManager();
        if ($failsOnUse) {
            $manager->configure($config);
            $manager->connection('c');
        } else {
            $manager->configure($config);
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string, 2?: bool}>
     */
    public function invalidConfigs(): array
    {
        return [
            'connections not array' => [['connections' => 'x'], '"connections" setting must be an array'],
            'definition not array' => [['connections' => ['c' => 'x']], 'definition of connection "c" must be an array'],
            'unknown default' => [['default' => 'zzz', 'connections' => ['c' => ['driver' => 'sqlite']]], 'default connection "zzz" is not defined'],
            'default not string' => [['default' => 5, 'connections' => ['c' => ['driver' => 'sqlite']]], 'default connection'],
            'no driver' => [['connections' => ['c' => []]], 'needs a "driver"', true],
            'bad driver' => [['connections' => ['c' => ['driver' => 'oracle']]], 'needs a "driver"', true],
            'class not a connection' => [['connections' => ['c' => ['class' => stdClass::class]]], 'must implement', true],
            'class not a string' => [['connections' => ['c' => ['class' => 5]]], 'must implement', true],
            'class missing' => [['connections' => ['c' => ['class' => 'Nope\\Missing']]], 'must implement', true],
        ];
    }

    private function addOne(ConnectionInterface $connection): int
    {
        return (int) $connection->fetchValue('SELECT 1 + ?', [1]);
    }
}
