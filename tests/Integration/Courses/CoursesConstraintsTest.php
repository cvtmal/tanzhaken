<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Courses\Entity\LessonStatus;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CoursesConstraintsTest extends KernelTestCase
{
    use DbalRows;

    public function test_a_student_is_enrolled_in_a_course_once(): void
    {
        self::bootKernel();
        $enrollment = EnrollmentFactory::createOne();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/enrollment\.course_id, enrollment\.student_id/');

        self::insertRow('enrollment', self::enrollmentRow($enrollment->getCourse()->getId(), $enrollment->getStudent()->getId()));
    }

    public function test_a_course_has_one_lesson_per_start_time(): void
    {
        self::bootKernel();
        $lesson = LessonSessionFactory::createOne();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessageMatches('/lesson_session\.course_id, lesson_session\.starts_at_utc/');

        self::insertRow('lesson_session', self::lessonRow($lesson->getCourse()->getId(), $lesson->getStartsAtUtc()));
    }

    public function test_course_needs_an_existing_teacher(): void
    {
        self::bootKernel();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('course', [
            'id' => Uuid::v7(),
            'teacher_id' => Uuid::v7(),
            'name' => 'Orphan course',
            'timezone' => 'Europe/Zurich',
            'weekday' => 2,
            'local_start_time' => '19:00:00',
            'duration_minutes' => 60,
            'starts_on' => '2026-01-06',
            'created_at' => new \DateTimeImmutable('2026-01-01 10:00:00'),
        ]);
    }

    public function test_enrollment_needs_an_existing_course(): void
    {
        self::bootKernel();
        $student = StudentFactory::createOne();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('enrollment', self::enrollmentRow(Uuid::v7(), $student->getId()));
    }

    public function test_enrollment_needs_an_existing_student(): void
    {
        self::bootKernel();
        $course = CourseFactory::createOne(['teacher' => TeacherFactory::createOne()]);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('enrollment', self::enrollmentRow($course->getId(), Uuid::v7()));
    }

    public function test_lesson_needs_an_existing_course(): void
    {
        self::bootKernel();

        $this->expectException(ForeignKeyConstraintViolationException::class);

        self::insertRow('lesson_session', self::lessonRow(Uuid::v7(), new \DateTimeImmutable('2026-01-06 18:00:00')));
    }

    /**
     * @return array<string, mixed>
     */
    private static function enrollmentRow(Uuid $courseId, Uuid $studentId): array
    {
        return [
            'id' => Uuid::v7(),
            'course_id' => $courseId,
            'student_id' => $studentId,
            'joined_at' => new \DateTimeImmutable('2026-01-01 10:00:00'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function lessonRow(Uuid $courseId, \DateTimeImmutable $startsAtUtc): array
    {
        return [
            'id' => Uuid::v7(),
            'course_id' => $courseId,
            'starts_at_utc' => $startsAtUtc,
            'ends_at_utc' => $startsAtUtc->modify('+60 minutes'),
            'status' => LessonStatus::Scheduled,
        ];
    }
}
