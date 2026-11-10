<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use Zenstruck\Foundry\Factory;
use Zenstruck\Foundry\LazyValue;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Helper for the factories whose two parents must belong to one teacher
 * (EnrollmentFactory, AttendanceFactory): turns a raw attribute into the object.
 *
 * @internal
 */
final class SameTeacher
{
    /**
     * @param bool $persist whether the factory creating the child persists (parents follow it)
     */
    public static function resolve(mixed $value, bool $persist): mixed
    {
        if ($value instanceof LazyValue) {
            $value = $value();
        }
        if ($value instanceof PersistentObjectFactory) {
            return $persist ? $value->create() : $value->withoutPersisting()->create();
        }
        if ($value instanceof Factory) {
            return $value->create();
        }

        return $value;
    }
}
