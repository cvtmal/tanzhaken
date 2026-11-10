<?php

declare(strict_types=1);

namespace App\Tests\Unit\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Courses\Exception\InvalidCourse;
use App\Students\Entity\Student;
use PHPUnit\Framework\TestCase;

final class EnrollmentTest extends TestCase
{
    public function test_it_enrolls_a_student_of_the_same_teacher(): void
    {
        $teacher = self::teacher('ann@example.com');
        $enrollment = new Enrollment(self::course($teacher), new Student($teacher, 'Mia', 'Example', self::now()), self::now(), '  Mia E. ');

        self::assertTrue($teacher->getId()->equals($enrollment->ownerId()));
        self::assertTrue($enrollment->isActive());
        self::assertEquals(self::now(), $enrollment->getJoinedAt());
        self::assertSame('Mia E.', $enrollment->getPublicCheckinLabel());
    }

    public function test_it_refuses_a_student_of_another_teacher(): void
    {
        $course = self::course(self::teacher('ann@example.com'));
        $student = new Student(self::teacher('bob@example.com'), 'Mia', 'Example', self::now());

        $this->expectException(InvalidCourse::class);

        new Enrollment($course, $student, self::now());
    }

    public function test_it_leaves_and_rejoins(): void
    {
        $teacher = self::teacher('ann@example.com');
        $enrollment = new Enrollment(self::course($teacher), new Student($teacher, 'Mia', 'Example', self::now()), self::now());
        $later = self::now()->modify('+1 week');

        $enrollment->leave($later);
        self::assertFalse($enrollment->isActive());
        self::assertEquals($later, $enrollment->getLeftAt());

        $enrollment->rejoin();
        self::assertTrue($enrollment->isActive());
        self::assertNull($enrollment->getLeftAt());
    }

    public function test_the_label_is_trimmed_empty_becomes_null_and_too_long_is_refused(): void
    {
        $teacher = self::teacher('ann@example.com');
        $enrollment = new Enrollment(self::course($teacher), new Student($teacher, 'Mia', 'Example', self::now()), self::now(), 'Mia');

        $enrollment->changeLabel('   ');
        self::assertNull($enrollment->getPublicCheckinLabel());

        $this->expectException(InvalidCourse::class);
        $enrollment->changeLabel(str_repeat('x', Enrollment::LABEL_MAX + 1));
    }

    private static function teacher(string $email): Teacher
    {
        return new Teacher('Ann Example', $email, 'hash', self::now());
    }

    private static function course(Teacher $teacher): Course
    {
        return new Course($teacher, 'Salsa', 'Europe/Zurich', 3, new \DateTimeImmutable('19:30'), 60, new \DateTimeImmutable('2026-09-01'), self::now());
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-10 10:00:00');
    }
}
