<?php declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * ORM table for the `failed_jobs` table.
 */
class FailedJobsTable extends Table
{
    /**
     * Configures the table name and entity class.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('failed_jobs');
        $this->setEntityClass('App\Model\Entity\FailedJob');
        $this->setPrimaryKey('id');
    }
}
