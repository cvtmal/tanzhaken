<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\Teacher;
use App\Account\Repository\TeacherRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads teachers for the `main` firewall. Login emails are normalised the same way they are
 * stored (`TeacherRepository::findOneByEmail()`), which Doctrine's entity provider cannot do
 * because entities have no `repositoryClass`. Also rehashes passwords on login when the `auto`
 * hasher's settings change (no audit event: it is not a teacher action).
 *
 * @implements UserProviderInterface<Teacher>
 */
final class TeacherUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly TeacherRepository $teachers,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): Teacher
    {
        $teacher = $this->teachers->findOneByEmail($identifier);
        if (null === $teacher) {
            $exception = new UserNotFoundException(\sprintf('No teacher with the email "%s".', $identifier));
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $teacher;
    }

    public function refreshUser(UserInterface $user): Teacher
    {
        if (!$user instanceof Teacher) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $teacher = $this->teachers->find($user->getId());
        if (null === $teacher) {
            $exception = new UserNotFoundException('The teacher of this session no longer exists.');
            $exception->setUserIdentifier($user->getUserIdentifier());

            throw $exception;
        }

        return $teacher;
    }

    public function supportsClass(string $class): bool
    {
        return Teacher::class === $class || is_subclass_of($class, Teacher::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Teacher) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->changePasswordHash($newHashedPassword);
        $this->em->flush();
    }
}
