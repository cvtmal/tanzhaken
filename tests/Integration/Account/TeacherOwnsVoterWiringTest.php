<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Entity\Teacher;
use App\Account\Security\TeacherOwnsVoter;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The voter is autoconfigured into the real authorization checker (SEC-2: cross-tenant access
 * is refused through the same path #[IsGranted] uses).
 */
#[ResetDatabase]
final class TeacherOwnsVoterWiringTest extends KernelTestCase
{
    public function test_the_authorization_checker_grants_only_the_owner(): void
    {
        self::bootKernel();
        $teacherA = TeacherFactory::createOne();
        $teacherB = TeacherFactory::createOne();
        $studentOfA = StudentFactory::createOne(['teacher' => $teacherA]);
        $studentOfB = StudentFactory::createOne(['teacher' => $teacherB]);
        self::logIn($teacherA);

        $checker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        self::assertInstanceOf(AuthorizationCheckerInterface::class, $checker);

        self::assertTrue($checker->isGranted(TeacherOwnsVoter::ATTRIBUTE, $studentOfA));
        self::assertFalse($checker->isGranted(TeacherOwnsVoter::ATTRIBUTE, $studentOfB));
        self::assertTrue($checker->isGranted('TEACHER_OWNS', $teacherA));
        self::assertFalse($checker->isGranted('TEACHER_OWNS', $teacherB));
    }

    private static function logIn(Teacher $teacher): void
    {
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($teacher, 'main', $teacher->getRoles()));
    }
}
