<?php declare(strict_types=1);

namespace App\Event;

use Cake\Event\Event;

class MatchReportCreatedEvent extends Event
{
    public function __construct(array $data)
    {
        parent::__construct('MatchReport.created', null, $data);
    }
}
