<?php declare(strict_types=1);

use Migrations\BaseMigration;

class CreateUsers extends BaseMigration
{
    /**
     * Create users table on database
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->table('users', ['id' => false, 'primary_key' => ['id']]);

        $table
            ->addColumn('id', 'biginteger', [
                'autoIncrement' => true,
                'signed' => false,
            ])
            ->addColumn('name', 'string', [
                'limit' => 255,
                'null' => false,
            ])
            ->addColumn('score', 'integer', [
                'default' => 0,
                'null' => false,
                'signed' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'null' => false,
            ])
            ->addColumn('updated_at', 'datetime', [
                'null' => false,
            ])
            ->create();
    }

    /**
     * Down create users table migration
     *
     * @return void
     */
    public function down(): void
    {
        $this->table('users')->drop()->save();
    }
}
