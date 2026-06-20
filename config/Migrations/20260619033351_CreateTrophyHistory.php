<?php declare(strict_types=1);

use Migrations\BaseMigration;

class CreateTrophyHistory extends BaseMigration
{
    /**
     * Create trophy_history history migration
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('trophy_history', ['id' => false, 'primary_key' => ['user_id', 'match_id']]);

        $table
            ->addColumn('user_id', 'biginteger', [
                'null' => false,
                'signed' => false,
            ])
            ->addColumn('match_id', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('score_before', 'integer', [
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('score_after', 'integer', [
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('score_delta', 'integer', [
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('reason', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
            ])
            ->create();

        // Performance indexes using Phinx's methods
        $table->addIndex(['user_id'], [
            'name' => 'idx_trophy_history_user'
        ]);
        $table->addIndex(['match_id'], [
            'name' => 'idx_trophy_history_match'
        ]);

        // Foreign keys
        $table->addForeignKey('user_id', 'users', 'id', [
            'delete' => 'CASCADE',
            'update' => 'CASCADE',
        ]);

        $table->save();
    }

    /**
     * Down of create trophy_history migration
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('trophy_history')->drop()->save();
    }
}
