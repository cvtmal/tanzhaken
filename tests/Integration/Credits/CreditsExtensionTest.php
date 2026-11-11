<?php

declare(strict_types=1);

namespace App\Tests\Integration\Credits;

use App\Account\Entity\Teacher;
use App\Credits\ReadModel\Balances;
use App\Credits\ReadModel\EnrollmentBalance;
use App\Credits\Twig\CreditsExtension;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreditsExtensionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-14 09:00:00');
    }

    public function test_course_balance_matches_balances(): void
    {
        $course = CourseFactory::createOne();
        $teacher = $course->getTeacher();
        $full = EnrollmentFactory::createOne(['course' => $course]);
        CreditGrantFactory::createOne(['enrollment' => $full]);
        $owing = EnrollmentFactory::createOne(['course' => $course]);
        AttendanceFactory::createOne([
            'lessonSession' => LessonSessionFactory::new(['course' => $course])->completed()->create(),
            'student' => $owing->getStudent(),
        ]);
        self::logIn($teacher);
        $extension = self::extension();

        $expected = (new Balances(self::connection()))->forCourse($teacher, $course->getId());
        self::assertEquals($expected[$full->getId()->toRfc4122()], $extension->courseBalance($course->getId(), $full->getId()));
        self::assertSame(-1, $extension->courseBalance($course->getId()->toRfc4122(), $owing->getId()->toRfc4122())?->balance);
        self::assertNull($extension->courseBalance($course->getId(), EnrollmentFactory::createOne()->getId()));
    }

    public function test_student_gives_balances_and_history(): void
    {
        $student = StudentFactory::createOne();
        $teacher = $student->getTeacher();
        $salsa = EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher, 'name' => 'Salsa']), 'student' => $student]);
        $tango = EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher, 'name' => 'Tango']), 'student' => $student]);
        CreditGrantFactory::createOne(['enrollment' => $salsa, 'quantity' => 5]);
        self::logIn($teacher);

        $credits = self::extension()->student($student->getId());

        self::assertEquals((new Balances(self::connection()))->forStudent($teacher, $student->getId()), $credits['balances']);
        self::assertSame([5, 0], array_map(static fn (EnrollmentBalance $b): int => $b->balance, $credits['balances']));
        self::assertSame([$salsa->getId()->toRfc4122()], array_keys($credits['history']));
        self::assertArrayNotHasKey($tango->getId()->toRfc4122(), $credits['history']);
    }

    public function test_renewal_count_and_course_open(): void
    {
        $teacher = TeacherFactory::createOne();
        $salsa = CourseFactory::createOne(['teacher' => $teacher]);
        EnrollmentFactory::createMany(2, ['course' => $salsa]);
        CreditGrantFactory::createOne(['enrollment' => EnrollmentFactory::new(['course' => $salsa])]);
        $empty = CourseFactory::createOne(['teacher' => $teacher]);
        $archived = CourseFactory::createOne(['teacher' => $teacher]);
        EnrollmentFactory::createOne(['course' => $archived]);
        $archived->archive(Clock::get()->now());
        self::entityManager()->flush();
        $foreign = CourseFactory::createOne();
        self::logIn($teacher);
        $extension = self::extension();

        self::assertSame(2, $extension->renewalCount($salsa->getId()));
        self::assertSame(0, $extension->renewalCount($empty->getId()->toRfc4122()));
        self::assertSame(0, $extension->renewalCount($archived->getId()));
        self::assertTrue($extension->courseOpen($salsa->getId()));
        self::assertFalse($extension->courseOpen($archived->getId()));
        self::assertFalse($extension->courseOpen($foreign->getId()));
    }

    public function test_calls_are_memoised(): void
    {
        $course = CourseFactory::createOne();
        $enrollments = EnrollmentFactory::createMany(20, ['course' => $course]);
        self::logIn($course->getTeacher());
        $extension = self::extension();
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);

        $holder->reset();
        foreach ($enrollments as $enrollment) {
            self::assertNotNull($extension->courseBalance($course->getId(), $enrollment->getId()));
            $extension->renewalCount($course->getId());
        }
        self::assertCount(2, $holder->getData()['default'] ?? []);

        $extension->reset();
        $holder->reset();
        $extension->courseBalance($course->getId(), $enrollments[0]->getId());
        self::assertCount(1, $holder->getData()['default'] ?? []);
    }

    public function test_anonymous_gets_empty_results(): void
    {
        $grant = CreditGrantFactory::createOne();
        $enrollment = $grant->getEnrollment();
        $courseId = $enrollment->getCourse()->getId();
        $extension = self::extension();

        self::assertSame(['balances' => [], 'history' => []], $extension->student($enrollment->getStudent()->getId()));
        self::assertNull($extension->courseBalance($courseId, $enrollment->getId()));
        self::assertSame(0, $extension->renewalCount($courseId));
        self::assertFalse($extension->courseOpen($courseId));
    }

    private static function extension(): CreditsExtension
    {
        $extension = self::getContainer()->get(CreditsExtension::class);
        self::assertInstanceOf(CreditsExtension::class, $extension);

        return $extension;
    }

    private static function logIn(Teacher $teacher): void
    {
        $storage = self::getContainer()->get('security.token_storage');
        self::assertInstanceOf(TokenStorageInterface::class, $storage);
        $storage->setToken(new UsernamePasswordToken($teacher, 'main', $teacher->getRoles()));
    }

    private static function entityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
