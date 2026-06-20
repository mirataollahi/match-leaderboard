<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMQ;

use Cake\Core\Configure;
use Cake\Log\Log;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPExceptionInterface;

/**
 * Manages a single shared RabbitMQ connection and channel (Singleton pattern).
 *
 * Handles graceful degradation — when RabbitMQ is unavailable the system
 * falls back to direct PostgreSQL writes.  Callers must check isAvailable()
 * before publishing.
 */
final class RabbitMQClient
{
    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /** @var AMQPStreamConnection|null Underlying AMQP connection */
    private ?AMQPStreamConnection $connection = null;

    /** @var AMQPChannel|null Shared channel */
    private ?AMQPChannel $channel = null;

    /** @var bool Whether the broker is reachable */
    private bool $available = false;

    /** @var array<string,string> Queue name map from config */
    private array $queues = [];

    /** Private constructor — use getInstance() */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * Returns the singleton RabbitMQClient instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Returns the active AMQP channel, or null when unavailable.
     */
    public function getChannel(): ?AMQPChannel
    {
        if (!$this->available) {
            return null;
        }

        return $this->channel;
    }

    /**
     * Returns true when the broker is reachable.
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Resolves a logical queue alias to its configured AMQP queue name.
     */
    public function getQueueName(string $alias): string
    {
        return $this->queues[$alias] ?? $alias;
    }

    /**
     * Attempts to reconnect to RabbitMQ.
     */
    public function reconnect(): void
    {
        $this->close();
        $this->connect();
    }

    /**
     * Closes the AMQP connection and channel gracefully.
     */
    public function close(): void
    {
        try {
            $this->channel?->close();
            $this->connection?->close();
        } catch (AMQPExceptionInterface) {
            // Ignore errors on close
        } finally {
            $this->channel    = null;
            $this->connection = null;
            $this->available  = false;
        }
    }

    /**
     * Establishes AMQP connection and declares required queues.
     */
    private function connect(): void
    {
        $config = Configure::read('RabbitMQ');
        $this->queues = $config['queues'] ?? [];

        try {
            $this->connection = new AMQPStreamConnection(
                $config['host'],
                $config['port'],
                $config['user'],
                $config['password'],
                $config['vhost'],
                connection_timeout: 3,
                read_write_timeout: 3,
            );

            $this->channel = $this->connection->channel();

            // Declare queues with durability so messages survive broker restarts
            foreach ($this->queues as $queueName) {
                $this->channel->queue_declare(
                    $queueName,
                    passive:    false,
                    durable:    true,
                    exclusive:  false,
                    auto_delete: false,
                );
            }

            $this->available = true;
        } catch (AMQPExceptionInterface $e) {
            Log::warning("RabbitMQ unavailable: {$e->getMessage()}");
            $this->available = false;
        }
    }

    /** Prevent cloning of the singleton */
    private function __clone() {}
}
