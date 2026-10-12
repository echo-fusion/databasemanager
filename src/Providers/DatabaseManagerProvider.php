<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager\Providers;

use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\Contracts\DatabaseManager\DatabaseManagerInterface;
use EchoFusion\Contracts\ServiceManager\SettableContainerInterface;
use EchoFusion\Contracts\ServiceProvider\ServiceProviderInterface;
use EchoFusion\DatabaseManager\DatabaseManager;
use LogicException;
use function sprintf;

class DatabaseManagerProvider implements ServiceProviderInterface
{
    public function register(SettableContainerInterface $container): void
    {
        $manager = new DatabaseManager();

        $container->set(DatabaseManagerInterface::class, static fn (): DatabaseManager => $manager);
        $container->set(ConnectionInterface::class, static fn (): ConnectionInterface => $manager->connection());
    }

    /**
     * @param array<string, mixed> $config the "database" section of the application configuration
     */
    public function boot(SettableContainerInterface $container, array $config = []): void
    {
        $manager = $container->get(DatabaseManagerInterface::class);
        if (!$manager instanceof DatabaseManager) {
            throw new LogicException(sprintf('%s must be a %s to be configured.', DatabaseManagerInterface::class, DatabaseManager::class));
        }

        $manager->configure($config);
    }
}
