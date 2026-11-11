<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\Teacher;
use App\Account\Exception\InvalidTeacher;
use App\Account\Form\ProfileData;
use App\Account\Repository\PasswordResetRequestRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use App\Shared\Time\Timezones;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The /account settings (ACC-4): display name, time zone, password change.
 *
 * The Teacher passed in is the security token's user (a managed instance), so after a password
 * change the session is re-serialised with the new hash at the end of the request: this session
 * stays logged in, the teacher's other sessions are logged out. Refusals are thrown before the
 * transaction opens (S2, D-027).
 */
final class AccountSettings
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PasswordResetRequestRepository $requests,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Expects data the form has validated. Writes nothing (and no audit event) when nothing changed.
     *
     * @throws InvalidTeacher the time zone is not a valid identifier, or the name is empty or too long
     */
    public function updateProfile(Teacher $teacher, ProfileData $data): void
    {
        $name = trim((string) $data->name);
        $timezone = (string) $data->timezone;

        if ('' === $name) {
            throw InvalidTeacher::emptyName();
        }
        if (mb_strlen($name) > Teacher::NAME_MAX) {
            throw InvalidTeacher::nameTooLong(Teacher::NAME_MAX);
        }
        if (!Timezones::isValid($timezone)) {
            throw InvalidTeacher::invalidTimezone($timezone);
        }

        $changed = [];
        if ($name !== $teacher->getName()) {
            $changed[] = 'name';
        }
        if ($timezone !== $teacher->getTimezone()) {
            $changed[] = 'timezone';
        }
        if ([] === $changed) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($teacher, $name, $timezone, $changed): void {
            if (\in_array('name', $changed, true)) {
                $teacher->rename($name);
            }
            if (\in_array('timezone', $changed, true)) {
                $teacher->changeTimezone($timezone);
            }
            $this->em->flush();
            $this->audit->record($teacher->getId(), ActorType::Teacher, 'teacher.profile_updated', 'teacher', $teacher->getId(), ['changed' => $changed]);
        });
    }

    /**
     * Expects a new password the form has validated (the form also checked the current one).
     * Pending reset links die with the change (P5), so an older link cannot undo it.
     */
    public function changePassword(Teacher $teacher, string $newPassword): void
    {
        $hash = $this->hasher->hashPassword($teacher, $newPassword);

        $this->em->wrapInTransaction(function () use ($teacher, $hash): void {
            $teacher->changePasswordHash($hash);
            $this->requests->deleteForTeacher($teacher);
            $this->em->flush();
            $this->audit->record($teacher->getId(), ActorType::Teacher, 'teacher.password_changed', 'teacher', $teacher->getId());
        });
    }
}
