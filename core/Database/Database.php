<?php

declare(strict_types=1);

namespace Wuyoo\Database;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

final class Database
{
    private Manager $capsule;

    public function __construct(array $config)
    {
        $this->capsule = new Manager();
        foreach ($config['connections'] as $name => $connection) {
            if (!in_array($connection['driver'], ['mysql', 'pgsql', 'sqlite'], true)) {
                throw new \InvalidArgumentException('Unsupported SQL driver');
            }
            $connection['options'] = array_replace($connection['options'] ?? [], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
            $this->capsule->addConnection($connection, $name);
        }
        $this->capsule->getDatabaseManager()->setDefaultConnection($config['default']);
        $this->capsule->bootEloquent();
    }

    public function connection(?string $name = null): Connection { return $this->capsule->getConnection($name); }

    public function close(): void
    {
        $failure = null;
        foreach ($this->capsule->getDatabaseManager()->getConnections() as $connection) {
            try { if ($connection->transactionLevel() > 0) { $connection->rollBack(0); } }
            catch (\Throwable $error) { $failure ??= $error; }
            finally { $connection->disconnect(); }
        }
        if ($failure !== null) { throw $failure; }
    }
}
