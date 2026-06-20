<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreateMatchReports extends BaseMigration
{
    /**
     * Create match reports table migration
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('match_reports',
            ['id' => false, 'primary_key' => ['id']]
        );

        $table
            ->addColumn('id', 'biginteger', [
                'autoIncrement' => true,
                'signed' => false,
            ])
            ->addColumn('request_id', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('user_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('match_id', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('result', 'string', [
                'limit' => 20,
                'null' => false,
            ])
            ->addColumn('score_delta', 'integer', [
                'default' => 0,
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('reported_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
            ])
            ->create();

        // Performance indexes - using Phinx's built-in methods instead of execute()
        $table->addIndex(['request_id'], [
            'unique' => true,
            'name' => 'idx_match_reports_request'
        ]);
        $table->addIndex(['user_id'], [
            'name' => 'idx_match_reports_user'
        ]);
        $table->addIndex(['match_id'], [
            'name' => 'idx_match_reports_match'
        ]);

        // Foreign key
        $table->addForeignKey('user_id', 'users', 'id', [
            'delete' => 'CASCADE',
            'update' => 'CASCADE',
        ]);

        $table->save();
    }

    /**
     * Down of create match reports migration
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('match_reports')->drop()->save();
    }
}
