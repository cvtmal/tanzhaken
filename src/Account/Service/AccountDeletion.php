<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\Teacher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Account deletion (ACC-6). One DELETE of the teacher row; every other row goes by ON DELETE
 * CASCADE (research E1); a table added later without the cascade makes `AccountDeletionTest`
 * fail. No audit event and no log line: the audit trail is the teacher's data and goes with it
 * (research D2).
 */
final class AccountDeletion
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Removes the teacher and every row that leads to the teacher, in one transaction.
     *
     * @throws \LogicException the teacher row no longer exists (cannot happen from the UI)
     */
    public function delete(Teacher $teacher): void
    {
        $this->em->wrapInTransaction(function () use ($teacher): void {
            $deleted = $this->em->getConnection()->executeStatement(
                'DELETE FROM teacher WHERE id = :id',
                ['id' => $teacher->getId()],
                ['id' => UuidType::NAME],
            );
            if (0 === $deleted) {
                throw new \LogicException('The teacher to delete does not exist.');
            }
        });

        $this->em->clear();
    }
}
