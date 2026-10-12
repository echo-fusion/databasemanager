<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Tests;

use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\Contracts\DatabaseManager\DatabaseManagerInterface;
use EchoFusion\Contracts\ServiceManager\SettableContainerInterface;
use EchoFusion\DatabaseManager\DatabaseManager;
use EchoFusion\DatabaseManager\Providers\DatabaseManagerProvider;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use function array_key_exists;
use function is_callable;

final class ProviderTest extends TestCase
{
    public function testRegisterAndBoot(): void
    {
        $container = new ArrayContainer();
        $provider = new DatabaseManagerProvider();

        $provider->register($container);
        $provider->boot($container, ['connections' => ['main' => ['driver' => 'sqlite']]]);

        $manager = $container->get(DatabaseManagerInterface::class);
        $this->assertInstanceOf(DatabaseManager::class, $manager);
        $this->assertSame($manager->connection(), $container->get(ConnectionInterface::class));
        $this->assertSame(1, $container->get(ConnectionInterface::class)->fetchValue('SELECT 1'));
    }

    public function testBootRequiresTheBundledManager(): void
    {
        $container = new ArrayContainer();
        $container->set(DatabaseManagerInterface::class, static fn () => new class() implements DatabaseManagerInterface {
            public function connection(?string $name = null): ConnectionInterface
            {
                throw new RuntimeException('unused');
            }

            public function has(string $name): bool
            {
                return false;
            }

            public function getDefaultConnectionName(): string
            {
                return 'x';
            }
        });

        $this->expectException(LogicException::class);
        (new DatabaseManagerProvider())->boot($container);
    }
}

final class ArrayContainer implements SettableContainerInterface
{
    /**
     * @var array<string, callable>
     */
    private array $factories = [];

    public function set(string $id, callable|string $service, bool $shared = false): void
    {
        $this->factories[$id] = is_callable($service) ? $service : static fn () => new $service();
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class('missing') extends RuntimeException implements NotFoundExceptionInterface {
            };
        }

        return ($this->factories[$id])();
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->factories);
    }
}
