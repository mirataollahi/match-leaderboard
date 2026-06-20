<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use Cake\Core\Configure;
use Redis;
use RedisException;

/**
 * Manages a single shared Redis connection (Singleton pattern).
 *
 * Callers should always use RedisClient::getInstance() instead of
 * constructing a new Redis object directly, ensuring one connection
 * is reused across the entire request lifecycle.
 */
final class RedisClient
{
    /** @var self|null Holds the singleton instance */
    private static ?self $instance = null;

    /** @var Redis|null The underlying phpredis connection */
    private ?Redis $connection = null;

    /** @var bool Whether the last connect attempt succeeded */
    private bool $available = false;

    /** Private constructor — use getInstance() */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * Returns the singleton RedisClient instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Returns the raw Redis connection, or null when unavailable.
     */
    public function getConnection(): ?Redis
    {
        if (!$this->available) {
            return null;
        }

        return $this->connection;
    }

    /**
     * Returns true when Redis is reachable and connected.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Attempts to (re)connect to Redis using app configuration.
     */
    public function reconnect(): void
    {
        $this->available = false;
        $this->connection = null;
        $this->connect();
    }

    /**
     * Establishes the Redis connection from Configure settings.
     */
    private function connect(): void
    {
        $config = Configure::read('Redis');

        try {
            $redis = new Redis();
            $connected = $redis->connect(
                $config['host'],
                $config['port'],
                $config['timeout'] ?? 2.0
            );

            if (!$connected) {
                return;
            }

            if (!empty($config['password'])) {
                $redis->auth($config['password']);
            }

            if (isset($config['database'])) {
                $redis->select((int)$config['database']);
            }

            $redis->ping();

            $this->connection = $redis;
            $this->available  = true;
        } catch (RedisException) {
            // Gracefully degrade — callers check isAvailable()
            $this->available = false;
        }
    }

    /** Prevent cloning of the singleton */
    private function __clone() {}
}
