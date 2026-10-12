<?php

declare(strict_types=1);

namespace EchoFusion\DatabaseManager;

use EchoFusion\Contracts\ConfigManager\ConfigInterface;
use EchoFusion\Contracts\DatabaseManager\ConnectionInterface;
use EchoFusion\Contracts\DatabaseManager\DatabaseManagerInterface;
use EchoFusion\DatabaseManager\Connections\PdoConnection;
use EchoFusion\DatabaseManager\Exceptions\ConnectionNotFoundException;
use EchoFusion\DatabaseManager\Exceptions\InvalidConfigException;
use EchoFusion\DatabaseManager\Query\QueryBuilder;
use function array_diff_key;
use function array_key_first;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Named, lazily created connections.
 *
 * <code>
 * $databases = DatabaseManager::fromConfig([
 *     'default' => 'main',
 *     'connections' => [
 *         'main' => ['driver' => 'mysql', 'host' => 'db', 'dbname' => 'app', 'user' => 'app', 'password' => 'secret'],
 *         'cache' => ['driver' => 'sqlite', 'path' => __DIR__ . '/cache.sqlite'],
 *         // swap the implementation: any class implementing ConnectionInterface, created with `new Class($config)`
 *         'orm' => ['class' => DoctrineConnection::class, 'driver' => 'pdo_mysql', 'host' => 'db', 'dbname' => 'app'],
 *     ],
 * ]);
 * </code>
 */
final class DatabaseManager implements DatabaseManagerInterface
{
    private const PDO_DRIVERS = ['mysql', 'pgsql', 'sqlite'];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $definitions = [];

    private ?string $default = null;

    /**
     * @var array<string, ConnectionInterface>
     */
    private array $connections = [];

    /**
     * @param array<string, mixed>|ConfigInterface $config
     *
     * @throws InvalidConfigException
     */
    public function __construct(array|ConfigInterface $config = [])
    {
        $this->configure($config);
    }

    /**
     * @param array<string, mixed>|ConfigInterface $config
     *
     * @throws InvalidConfigException
     */
    public static function fromConfig(array|ConfigInterface $config): self
    {
        return new self($config);
    }

    /**
     * Replace the configuration. Already created connections are dropped.
     *
     * @param array<string, mixed>|ConfigInterface $config `default` (connection name) and `connections` (name => definition)
     *
     * @throws InvalidConfigException
     */
    public function configure(array|ConfigInterface $config): void
    {
        $config = $config instanceof ConfigInterface ? $config->all() : $config;

        $connections = $config['connections'] ?? [];
        if (!is_array($connections)) {
            throw new InvalidConfigException('The "connections" setting must be an array.');
        }

        $definitions = [];
        foreach ($connections as $name => $definition) {
            if (!is_array($definition)) {
                throw new InvalidConfigException(sprintf('The definition of connection "%s" must be an array.', (string) $name));
            }
            $definitions[(string) $name] = $definition;
        }

        $default = $config['default'] ?? array_key_first($definitions);
        if ($definitions !== [] && (!is_string($default) || !isset($definitions[$default]))) {
            throw new InvalidConfigException(sprintf('The default connection "%s" is not defined in "connections".', is_string($default) ? $default : ''));
        }

        $this->definitions = $definitions;
        $this->default = $definitions === [] ? null : (string) $default;
        $this->connections = [];
    }

    public function connection(?string $name = null): ConnectionInterface
    {
        $name ??= $this->getDefaultConnectionName();

        return $this->connections[$name] ??= $this->create($name);
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function getDefaultConnectionName(): string
    {
        return $this->default ?? throw new InvalidConfigException('No database connections are configured.');
    }

    /**
     * Shortcut for `QueryBuilder::table($manager->connection($connection), $table)`.
     */
    public function table(string $table, ?string $connection = null): QueryBuilder
    {
        return QueryBuilder::table($this->connection($connection), $table);
    }

    private function create(string $name): ConnectionInterface
    {
        $definition = $this->definitions[$name] ?? throw new ConnectionNotFoundException(sprintf('Database connection "%s" is not defined.', $name));

        $class = $definition['class'] ?? null;
        if ($class !== null) {
            if (!is_string($class) || !is_a($class, ConnectionInterface::class, true)) {
                throw new InvalidConfigException(sprintf('The "class" of connection "%s" must implement %s.', $name, ConnectionInterface::class));
            }

            return new $class(array_diff_key($definition, ['class' => true]));
        }

        $driver = $definition['driver'] ?? null;
        if (!is_string($driver) || !in_array($driver, self::PDO_DRIVERS, true)) {
            throw new InvalidConfigException(sprintf(
                'Connection "%s" needs a "driver" (%s) or a "class" implementing %s.',
                $name,
                implode(', ', self::PDO_DRIVERS),
                ConnectionInterface::class,
            ));
        }

        return new PdoConnection($definition);
    }
}
