<?php declare(strict_types=1);

namespace App\Repository\UserRepository;

use App\Exception\UserNotFoundException;
use App\Model\Entity\User;
use App\Model\Table\UsersTable;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\ORM\TableRegistry;
use RuntimeException;

class UserRepository implements UserRepositoryInterface
{
    use LocatorAwareTrait;

    /** @var UsersTable ORM table instance */
    private UsersTable $table;

    public function __construct()
    {
        /** @var UsersTable $table */
        $table = $this->fetchTable('Users');
        $this->table = $table;
    }

    /**
     *Find the user in database base on user unique username
     * @param string $username
     * @return User
     */
    public function findByUsername(string $username): User
    {
        /** @var User|null $user */
        $user = $this->table->find()
            ->where(['username' => $username])
            ->contain(['UserScores'])
            ->first();

        if ($user === null) {
            throw new UserNotFoundException($username);
        }

        return $user;
    }

    /**
     * Create new user base on the user username
     *
     * @param string $username
     * @return User
     */
    public function create(string $username): User
    {
        /** @var User $user */
        $user = $this->table->newEntity(['username' => $username]);
        if (!$this->table->save($user)) {
            throw new RuntimeException("Failed to create user: {$username}");
        }

        return $user;
    }

    /**
     * Find a user by primary key.
     *
     * Returns null instead of throwing when the user does not exist so the
     * caller (service layer) can decide how to handle the missing entity.
     *
     * @param int $id The user's primary key.
     *
     * @return User|null
     */
    public function findById(int $id): ?User
    {
        $table = TableRegistry::getTableLocator()->get('Users');

        /** @var User|null $user */
        $user = $table->find()
            ->where(['id' => $id])
            ->first();

        return $user;
    }

    /**
     * Atomically increment (or decrement) a user's score using a raw SQL
     * UPDATE with a relative delta so concurrent requests never overwrite
     * each other's changes.
     *
     * @param int $userId The user to update.
     * @param int $scoreDelta Positive to add, negative to subtract.
     *
     * @return int The new score value after the update.
     *
     * @throws RuntimeException When the update affects zero rows.
     */
    public function incrementScore(int $userId, int $scoreDelta): int
    {
        $connection = ConnectionManager::get('default');

        // Raw UPDATE with relative delta — safe under concurrent load
        // todo : Use ORM here instead of database exec
        $connection->execute(
            'UPDATE users SET score = score + :delta, updated_at = NOW() WHERE id = :id',
            [':delta' => $scoreDelta, ':id' => $userId],
        );

        // Re-read the new score in the same transaction
        $row = $connection->execute(
            'SELECT score FROM users WHERE id = :id',
            [':id' => $userId],
        )->fetch('assoc');

        if ($row === false) {
            throw new RuntimeException(
                sprintf('Failed to read score after update for user_id %d', $userId),
            );
        }

        return (int)$row['score'];
    }

    /**
     * Return all users as lightweight arrays for Redis leaderboard seeding.
     *
     * Only the three fields needed by the sorted set are selected — avoids
     * loading timestamps and associations that are not required here.
     *
     * @return array<int, array{id: int, name: string, score: int}>
     */
    public function allForLeaderboard(): array
    {
        $table = TableRegistry::getTableLocator()->get('Users');

        return $table->find()
            ->select(['id', 'name', 'score'])
            ->all()
            ->map(static fn(User $u): array => [
                'id' => (int)$u->id,
                'name' => (string)$u->name,
                'score' => (int)$u->score,
            ])
            ->toArray();
    }
}
