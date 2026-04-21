<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;

final class RedisService
{
    private ?\Redis $client = null;

    private EnvService $envService;

    public function __construct(?EnvService $envService = null, ?\Redis $client = null)
    {
        $this->envService = $envService ?? new EnvService();
        $this->client = $client;
    }

    public function setWithTtl(string $key, int $ttl, string $value): void
    {
        $result = $this->client()->setEx($key, $ttl, $value);

        if ($result !== true) {
            throw new InfrastructureException('Unable to set Redis value.');
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->client()->get($key);

        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    public function delete(string $key): void
    {
        $this->client()->del($key);
    }

    private function client(): \Redis
    {
        if ($this->client instanceof \Redis) {
            return $this->client;
        }

        if (!class_exists(\Redis::class)) {
            throw new InfrastructureException('The PHP Redis extension is not installed.');
        }

        $redis = new \Redis();
        $host = (string) $this->envService->get('REDIS_HOST', '127.0.0.1');
        $port = $this->envService->int('REDIS_PORT', 6379);
        $timeout = (float) $this->envService->get('REDIS_TIMEOUT', 2.5);
        $connected = $redis->connect($host, $port, $timeout);

        if ($connected !== true) {
            throw new InfrastructureException('Unable to connect to Redis.');
        }

        $password = (string) $this->envService->get('REDIS_PASSWORD', '');

        if ($password !== '' && $redis->auth($password) !== true) {
            throw new InfrastructureException('Redis authentication failed.');
        }

        $database = $this->envService->int('REDIS_DATABASE', 0);

        if ($database > 0 && $redis->select($database) !== true) {
            throw new InfrastructureException('Unable to select Redis database.');
        }

        $this->client = $redis;

        return $this->client;
    }
}
