<?php declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;
use DateTime;

/**
 * The failed job table columns in database
 *
 * @property string $id The failed job id
 * @property string $queue The failed job belongs to the queue
 * @property array $payload The failed job payload and data
 * @property string $error The failed job error text
 * @property int $attempts The number of try attempte for the job
 * @property DateTime $failed_at Last job failed at data time
 */
class FailedJob extends Entity
{
    /**
     * The accessible column in failed job table in database
     * @var array|true[]
     */
    protected array $_accessible = [
        'queue' => true,
        'payload' => true,
        'error' => true,
        'attempts' => true,
        'failed_at' => true,
    ];
}
