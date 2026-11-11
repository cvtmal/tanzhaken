<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Enrolling, removing and labelling students on a course's roster. Today is 2026-03-01 10:00 UTC.
 */
final class RosterTest extends KernelTestCase
{
    use BuildsCourseServices;
    use ClockSensitiveTrait;

    public function test_enroll_creates_an_active_enrollment_and_audits_it(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();

        $enrollment = self::roster()->enroll($teacher, $course->getId(), $student->getId(), '  Alex ');

        self::assertTrue($enrollment->isActive());
        self::assertSame('Alex', $enrollment->getPublicCheckinLabel());
        self::assertSame(1, self::countRows('enrollment'));
        self::assertSame([[
            'course_id' => $course->getId()->toRfc4122(),
            'student_id' => $student->getId()->toRfc4122(),
            'label' => 'Alex',
        ]], self::auditDetails('enrollment.created'));
    }

    public function test_enroll_refuses_an_active_enrollment_an_archived_student_and_an_archived_course(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $roster->enroll($teacher, $course->getId(), $student->getId());
        $archivedStudent = StudentFactory::new()->archived()->create(['teacher' => $teacher]);
        $archivedCourse = CourseFactory::new()->archived()->create(['teacher' => $teacher]);

        self::assertRefused(InvalidCourse::alreadyEnrolled(), static fn () => $roster->enroll($teacher, $course->getId(), $student->getId()));
        self::assertRefused(InvalidCourse::studentArchived(), static fn () => $roster->enroll($teacher, $course->getId(), $archivedStudent->getId()));
        self::assertRefused(InvalidCourse::courseArchived(), static fn () => $roster->enroll($teacher, $archivedCourse->getId(), $student->getId()));
        self::assertSame(1, self::countRows('enrollment'));
        self::assertCount(1, self::auditDetails('enrollment.created'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    public function test_enroll_answers_not_found_for_another_teachers_course_or_student(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $otherStudent = StudentFactory::createOne();
        $otherCourse = CourseFactory::createOne();
        $roster = self::roster();

        self::assertRefused(NotFound::student($otherStudent->getId()), static fn () => $roster->enroll($teacher, $course->getId(), $otherStudent->getId()));
        self::assertRefused(NotFound::course($otherCourse->getId()), static fn () => $roster->enroll($teacher, $otherCourse->getId(), $student->getId()));
        self::assertSame(0, self::countRows('enrollment'));
    }

    public function test_remove_keeps_the_row_and_the_students_attendance_and_credit_history(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $enrollment = $roster->enroll($teacher, $course->getId(), $student->getId());
        AttendanceFactory::createOne(['lessonSession' => LessonSessionFactory::createOne(['course' => $course]), 'student' => $student]);
        CreditGrantFactory::createOne(['enrollment' => $enrollment]);

        self::mockTime('2026-03-02 09:00:00');
        $roster->remove($teacher, $enrollment->getId());

        self::assertSame(
            '2026-03-02 09:00:00',
            self::connection()->fetchOne('SELECT left_at FROM enrollment WHERE id = ?', [$enrollment->getId()], [UuidType::NAME]),
        );
        self::assertSame(1, self::countRows('attendance'));
        self::assertSame(1, self::countRows('credit_grant'));
        self::assertCount(1, self::auditDetails('enrollment.left'));
        self::assertRefused(InvalidCourse::enrollmentAlreadyLeft(), static fn () => $roster->remove($teacher, $enrollment->getId()));
        self::assertRefused(NotFound::enrollment($enrollment->getId()), static fn () => $roster->remove(TeacherFactory::createOne(), $enrollment->getId()));
    }

    public function test_enrolling_a_student_who_left_rejoins_the_same_enrollment(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $first = $roster->enroll($teacher, $course->getId(), $student->getId(), 'Alex');
        $roster->remove($teacher, $first->getId());

        $again = $roster->enroll($teacher, $course->getId(), $student->getId(), 'Alex K.');

        self::assertTrue($first->getId()->equals($again->getId()));
        self::assertTrue($again->isActive());
        self::assertSame('Alex K.', $again->getPublicCheckinLabel());
        self::assertSame(1, self::countRows('enrollment'));
        self::assertNull(self::connection()->fetchOne('SELECT left_at FROM enrollment'));
        self::assertCount(1, self::auditDetails('enrollment.rejoined'));
    }

    public function test_remove_and_change_label_refuse_on_an_archived_course(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $enrollment = $roster->enroll($teacher, $course->getId(), $student->getId());
        self::courseCatalog()->archive($teacher, $course->getId());

        self::assertRefused(InvalidCourse::courseArchived(), static fn () => $roster->remove($teacher, $enrollment->getId()));
        self::assertRefused(InvalidCourse::courseArchived(), static fn () => $roster->changeLabel($teacher, $enrollment->getId(), 'Alex'));
    }

    public function test_a_label_is_unique_among_active_enrollments_ignoring_case(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $holder = $roster->enroll($teacher, $course->getId(), $student->getId(), 'Alex');
        $second = StudentFactory::createOne(['teacher' => $teacher]);
        $third = StudentFactory::createOne(['teacher' => $teacher]);
        $other = $roster->enroll($teacher, $course->getId(), $third->getId());

        self::assertRefused(InvalidCourse::labelTaken('alex'), static fn () => $roster->enroll($teacher, $course->getId(), $second->getId(), ' alex '));
        self::assertRefused(InvalidCourse::labelTaken('ALEX'), static fn () => $roster->changeLabel($teacher, $other->getId(), 'ALEX'));

        $roster->remove($teacher, $holder->getId());
        $roster->changeLabel($teacher, $other->getId(), 'alex');

        self::assertSame('alex', $other->getPublicCheckinLabel());
        self::assertSame([['from' => null, 'to' => 'alex']], self::auditDetails('enrollment.label_changed'));
    }

    public function test_a_label_in_another_course_does_not_count(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $roster->enroll($teacher, $course->getId(), $student->getId(), 'Alex');
        $otherCourse = CourseFactory::createOne(['teacher' => $teacher]);

        $enrollment = $roster->enroll($teacher, $otherCourse->getId(), $student->getId(), 'Alex');

        self::assertSame('Alex', $enrollment->getPublicCheckinLabel());
    }

    public function test_change_label_sets_clears_and_ignores_an_unchanged_label(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $enrollment = $roster->enroll($teacher, $course->getId(), $student->getId(), 'Alex');

        $roster->changeLabel($teacher, $enrollment->getId(), ' Alex ');
        self::assertSame([], self::auditDetails('enrollment.label_changed'));

        $roster->changeLabel($teacher, $enrollment->getId(), 'Alex K.');
        $roster->changeLabel($teacher, $enrollment->getId(), '');

        self::assertNull($enrollment->getPublicCheckinLabel());
        self::assertNull(self::connection()->fetchOne('SELECT public_checkin_label FROM enrollment'));
        self::assertSame(
            [['from' => 'Alex', 'to' => 'Alex K.'], ['from' => 'Alex K.', 'to' => null]],
            self::auditDetails('enrollment.label_changed'),
        );
    }

    public function test_a_label_longer_than_40_characters_is_refused_before_the_transaction(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $long = str_repeat('a', Enrollment::LABEL_MAX + 1);

        self::assertRefused(
            InvalidCourse::tooLong('check-in label', Enrollment::LABEL_MAX),
            static fn () => $roster->enroll($teacher, $course->getId(), $student->getId(), $long),
        );
        $enrollment = $roster->enroll($teacher, $course->getId(), $student->getId(), 'Alex');
        self::assertRefused(
            InvalidCourse::tooLong('check-in label', Enrollment::LABEL_MAX),
            static fn () => $roster->changeLabel($teacher, $enrollment->getId(), $long),
        );

        self::assertSame('Alex', $enrollment->getPublicCheckinLabel());
        self::assertSame([], self::auditDetails('enrollment.label_changed'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    public function test_change_label_refuses_a_left_enrollment_and_another_teachers_enrollment(): void
    {
        [$teacher, $course, $student] = self::setUpRoster();
        $roster = self::roster();
        $enrollment = $roster->enroll($teacher, $course->getId(), $student->getId());
        $roster->remove($teacher, $enrollment->getId());

        self::assertRefused(InvalidCourse::enrollmentAlreadyLeft(), static fn () => $roster->changeLabel($teacher, $enrollment->getId(), 'Alex'));
        self::assertRefused(
            NotFound::enrollment($enrollment->getId()),
            static fn () => $roster->changeLabel(TeacherFactory::createOne(), $enrollment->getId(), 'Alex'),
        );
    }

    /**
     * @return array{Teacher, Course, Student}
     */
    private static function setUpRoster(): array
    {
        self::bootKernel();
        self::mockTime('2026-03-01 10:00:00');
        $teacher = TeacherFactory::createOne();

        return [$teacher, CourseFactory::createOne(['teacher' => $teacher]), StudentFactory::createOne(['teacher' => $teacher])];
    }
}
