<?php

declare(strict_types=1);

namespace App\Shared\Security;

use Symfony\Component\Uid\Uuid;

/**
 * The teacher as Shared sees it: an ID and nothing else. Doctrine resolves this
 * interface to the Account module's Teacher entity
 * (config/packages/doctrine_resolve_target_entities.yaml), so Shared can map a
 * foreign key to the teacher table without depending on Account.
 */
interface TeacherAccount
{
    public function getId(): Uuid;
}
