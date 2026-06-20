<?php

declare(strict_types=1);

namespace App\Infrastructure\RabbitMQ;

use Cake\Log\Log;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes messages to RabbitMQ queues.
 *
 * Wraps the raw AMQP channel with serialization and error handling.
 * Returns false when the broker is unavailable so callers can fall back.
 */
class MessagePublisher
{
    /** @var RabbitMQClient Shared broker client */
    private RabbitMQClient $client;

    public function __construct()
    {
        $this->client = RabbitMQClient::getInstance();
    }

    /**
     * Publishes a JSON-serialized payload to the given queue alias.
     *
     * @param string               $queueAlias Logical alias defined in config (e.g. 'score_updates')
     * @param array<string, mixed> $payload    Data to publish
     * @return bool True on success, false when broker is unavailable or publish fails
     */
    public function publish(string $queueAlias, array $payload): bool
    {
        $channel = $this->client->getChannel();

        if ($channel === null) {
            Log::warning("MessagePublisher: broker unavailable, dropping message for queue '{$queueAlias}'");
            return false;
        }

        $queueName = $this->client->getQueueName($queueAlias);

        try {
            $message = new AMQPMessage(
                json_encode($payload, JSON_THROW_ON_ERROR),
                [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type'  => 'application/json',
                ]
            );

            $channel->basic_publish($message, '', $queueName);

            return true;
        } catch (AMQPExceptionInterface|\JsonException $e) {
            Log::error("MessagePublisher: failed to publish to '{$queueAlias}': {$e->getMessage()}");
            return false;
        }
    }
}
