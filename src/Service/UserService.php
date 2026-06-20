<?php declare(strict_types=1);

namespace App\Service;

use App\Exception\UserNotFoundException;
use App\Model\Entity\User;
use App\Repository\UserRepository\UserRepositoryInterface;
use RuntimeException;

class UserService
{
    /** @var UserRepositoryInterface Underlying user persistence layer */
    private UserRepositoryInterface $userRepo;

    public function __construct(UserRepositoryInterface $userRepo)
    {
        $this->userRepo = $userRepo;
    }

    /**
     * Returns an existing user by ID.
     *
     * @throws UserNotFoundException
     */
    public function getUser(string $userId): User
    {
        return $this->userRepo->findById((int)$userId);
    }

    /**
     * Creates a new user with the given username.
     *
     * @throws RuntimeException When the username is already taken or save fails
     */
    public function register(string $username): User
    {
        return $this->userRepo->create($username);
    }
}
