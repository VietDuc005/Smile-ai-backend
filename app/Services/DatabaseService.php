<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use PDO;
use PDOException;

final class DatabaseService
{
    /** @var array<string, PDO> */
    private static array $sharedConnections = [];

    private ?PDO $connection = null;

    private EnvService $envService;

    public function __construct(?EnvService $envService = null, ?PDO $connection = null)
    {
        $this->envService = $envService ?? new EnvService();
        $this->connection = $connection;
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        try {
            $driver = strtolower((string) $this->envService->get('DB_CONNECTION', 'mysql'));
            $database = (string) $this->envService->get('DB_DATABASE', '');
            $host = (string) $this->envService->get('DB_HOST', '127.0.0.1');
            $port = (string) $this->envService->get('DB_PORT', '3306');
            $username = (string) $this->envService->get('DB_USERNAME', '');
            $password = (string) $this->envService->get('DB_PASSWORD', '');
            $cacheKey = implode('|', [$driver, $host, $port, $database, $username]);

            if (isset(self::$sharedConnections[$cacheKey])) {
                $this->connection = self::$sharedConnections[$cacheKey];

                return $this->connection;
            }

            $dsn = match ($driver) {
                'pgsql', 'postgres', 'postgresql' => sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $host,
                    $port,
                    $database
                ),
                default => sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $host,
                    $port,
                    $database
                ),
            };

            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$sharedConnections[$cacheKey] = $this->connection;

            return $this->connection;
        } catch (PDOException $exception) {
            throw new InfrastructureException('Unable to connect to the database.', 0, $exception);
        }
    }
}
