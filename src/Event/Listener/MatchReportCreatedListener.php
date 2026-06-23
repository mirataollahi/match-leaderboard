<?php

namespace App\Event\Listener;

use Cake\Event\EventInterface;
use Cake\Event\EventListenerInterface;

class MatchReportCreatedListener implements EventListenerInterface
{
    public function implementedEvents(): array
    {
        return [
            'MatchReport.created' => 'onCreated',
        ];
    }

    public function onCreated(EventInterface $event): void
    {
        $data = $event->getData();

        // Redis leaderboard update
        $this->updateLeaderboard($data);

        // Cache idempotency result
        $this->storeCache($data);

        // Logging
        $this->log($data);
    }

    private function updateLeaderboard(array $data): void
    {
        // call redis here
    }

    private function storeCache(array $data): void
    {
        // redis idempotency store
    }

    private function log(array $data): void
    {
        // logger
    }
}
