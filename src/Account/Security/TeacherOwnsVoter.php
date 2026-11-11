<?php

declare(strict_types=1);

namespace App\Account\Security;

use App\Account\Entity\Teacher;
use App\Shared\Security\TeacherOwned;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The one ownership check (S4): grants `TEACHER_OWNS` on a {@see TeacherOwned} subject only to
 * the teacher who owns it. Use it with a 404, never a 403, so a foreign ID looks unknown:
 *
 *     #[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'course', statusCode: 404)]
 *
 * @extends Voter<string, TeacherOwned>
 */
final class TeacherOwnsVoter extends Voter
{
    public const ATTRIBUTE = 'TEACHER_OWNS';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute && $subject instanceof TeacherOwned;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof Teacher && $subject->ownerId()->equals($user->getId());
    }
}
