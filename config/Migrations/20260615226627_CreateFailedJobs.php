<?php declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Creates the `failed_jobs` table.
 * Persists RabbitMQ messages that could not be processed after all
 * retries.  Used for audit, alerting, and manual or automated replay.
 */
class CreateFailedJobs extends BaseMigration
{

    /**
     * Create failed jobs table in database
     *
     * @return void
     */
    public function up(): void
    {
        $this->table('failed_jobs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'uuid', [
                'comment' => 'Primary key — UUID',
                'null' => false,
            ])
            ->addColumn('queue', 'string', [
                'comment' => 'RabbitMQ queue name the message came from',
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('payload', 'text', [
                'comment' => 'JSON-encoded message payload',
                'null' => false,
            ])
            ->addColumn('error', 'text', [
                'comment' => 'Exception message or error description',
                'null' => true,
                'default' => null,
            ])
            ->addColumn('attempts', 'integer', [
                'comment' => 'Number of processing attempts made',
                'default' => 0,
                'null' => false,
            ])
            ->addColumn('failed_at', 'timestamp', [
                'comment' => 'When the final failure was recorded',
                'default' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            // Index for queue-specific queries and monitoring
            ->addIndex(['queue'], [
                'name' => 'idx_failed_jobs_queue',
            ])
            ->addIndex(['failed_at'], [
                'name' => 'idx_failed_jobs_failed_at',
            ])
            ->create();

        $this->execute("ALTER TABLE failed_jobs ALTER COLUMN id SET DEFAULT gen_random_uuid()");
    }

    /**
     * Down failed jobs table in database
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('failed_jobs')->drop()->save();
    }
}
