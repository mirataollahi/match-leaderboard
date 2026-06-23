<?php declare(strict_types=1);

namespace App\Repository\UserRepository;

use App\Exception\UserNotFoundException;
use App\Model\Entity\User;
use RuntimeException;

/**
 * Contract for user persistence operations.
 */
interface UserRepositoryInterface
{
    /**
     * Creates and persists a new user with the given username.
     *
     * @throws RuntimeException When save fails
     */
    public function create(string $username): User;

    /**
     * Find a single user by primary key.
     *
     * @param int $id The user's primary key.
     *
     * @return User|null  The entity, or null when not found.
     */
    public function findById(int $id): ?User;

    /**
     * Atomically increment (or decrement) a user's score by delta.
     *
     * Must be called inside an existing transaction.
     *
     * @param int $userId The user to update.
     * @param int $scoreDelta Positive to add, negative to subtract.
     *
     * @return int The new score value after the update.
     */
    public function incrementScore(int $userId, int $scoreDelta): int;

    /**
     * Fetch all users needed to seed the Redis leaderboard.
     *
     * @return array<int, array{id: int, name: string, score: int}>
     */
    public function allForLeaderboard(): array;
}
