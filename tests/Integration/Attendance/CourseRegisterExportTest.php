<?php

declare(strict_types=1);

namespace App\Tests\Integration\Attendance;

use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\ReadModel\CourseRegisterExport;
use App\Attendance\ReadModel\CourseRegisterExportRow;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CourseRegisterExportTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    private const NOW = '2026-03-10 20:05:00';

    public function test_past_lessons_by_student_with_their_marks(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        $course = CourseFactory::createOne();
        $first = self::lesson($course, '2026-03-03 18:00');
        $second = self::lesson($course, '2026-03-10 18:00');
        $ben = self::enrolled($course, 'Ben', 'Brun');
        $anna = self::enrolled($course, 'Anna', 'Arnold');
        self::mark($first, $anna, AttendanceStatus::Present);
        self::mark($second, $anna, AttendanceStatus::Absent);
        self::mark($second, $ben, AttendanceStatus::Present);

        $rows = self::export($course);

        self::assertSame([
            ['2026-03-03 18:00', 'Arnold', 'present'],
            ['2026-03-03 18:00', 'Brun', null],
            ['2026-03-10 18:00', 'Arnold', 'absent'],
            ['2026-03-10 18:00', 'Brun', 'present'],
        ], self::summary($rows));
        self::assertTrue($rows[0]->lessonId->equals($first->getId()));
        self::assertTrue($rows[0]->studentId->equals($anna->getId()));
        self::assertSame('Anna', $rows[0]->firstName);
    }

    public function test_future_lessons_appear_only_with_a_mark_and_cancelled_ones_never(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        $course = CourseFactory::createOne();
        self::lesson($course, '2026-03-17 18:00');
        $markedLater = self::lesson($course, '2026-03-24 18:00');
        LessonSessionFactory::new(['course' => $course, 'startsAtUtc' => new \DateTimeImmutable('2026-03-03 18:00'), 'endsAtUtc' => new \DateTimeImmutable('2026-03-03 19:00')])->cancelled()->create();
        $anna = self::enrolled($course, 'Anna', 'Arnold');
        self::mark($markedLater, $anna, AttendanceStatus::Absent);

        self::assertSame([['2026-03-24 18:00', 'Arnold', 'absent']], self::summary(self::export($course)));
    }

    public function test_a_left_student_appears_only_where_marked(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        $course = CourseFactory::createOne();
        $first = self::lesson($course, '2026-03-03 18:00');
        self::lesson($course, '2026-03-10 18:00');
        $marked = self::enrolled($course, 'Mia', 'Marked', left: true);
        self::enrolled($course, 'Leo', 'Left', left: true);
        self::mark($first, $marked, AttendanceStatus::Present);

        self::assertSame([['2026-03-03 18:00', 'Marked', 'present']], self::summary(self::export($course)));
    }

    public function test_a_student_is_not_listed_for_lessons_before_joining(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        $course = CourseFactory::createOne();
        self::lesson($course, '2026-03-03 18:00');
        self::lesson($course, '2026-03-10 18:00');
        self::enrolled($course, 'Nora', 'New', joinedAt: '2026-03-05 10:00');

        self::assertSame([['2026-03-10 18:00', 'New', null]], self::summary(self::export($course)));
    }

    public function test_another_teachers_course_and_other_courses_stay_out(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        $course = CourseFactory::createOne();
        self::lesson($course, '2026-03-03 18:00');
        self::enrolled($course, 'Anna', 'Arnold');
        $other = CourseFactory::createOne(['teacher' => $course->getTeacher()]);
        self::lesson($other, '2026-03-04 18:00');
        self::enrolled($other, 'Otto', 'Other');

        self::assertSame([['2026-03-03 18:00', 'Arnold', null]], self::summary(self::export($course)));
        self::assertSame([], self::exporter()->forCourse(TeacherFactory::createOne(), $course->getId(), new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rosterSizes(): iterable
    {
        yield '3 students' => [3];
        yield '20 students' => [20];
    }

    #[DataProvider('rosterSizes')]
    public function test_one_query_whatever_the_size(int $size): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        $course = CourseFactory::createOne();
        $lessons = [self::lesson($course, '2026-03-03 18:00'), self::lesson($course, '2026-03-10 18:00')];
        for ($i = 0; $i < $size; ++$i) {
            $student = self::enrolled($course, 'S'.$i, 'Student'.$i);
            self::mark($lessons[$i % 2], $student, AttendanceStatus::Present);
        }
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $exporter = self::exporter();

        $holder->reset();
        self::assertCount(2 * $size, self::export($course, $exporter));

        self::assertCount(1, $holder->getData()['default'] ?? []);
    }

    private static function lesson(Course $course, string $startsAtUtc): LessonSession
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc);

        return LessonSessionFactory::createOne(['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')]);
    }

    private static function enrolled(Course $course, string $firstName, string $lastName, string $joinedAt = '2026-01-01 10:00', bool $left = false): Student
    {
        $student = StudentFactory::createOne(['teacher' => $course->getTeacher(), 'firstName' => $firstName, 'lastName' => $lastName]);
        $factory = EnrollmentFactory::new(['course' => $course, 'student' => $student, 'now' => new \DateTimeImmutable($joinedAt)]);
        ($left ? $factory->left() : $factory)->create();

        return $student;
    }

    private static function mark(LessonSession $lesson, Student $student, AttendanceStatus $status): void
    {
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student, 'status' => $status]);
    }

    /**
     * @return list<CourseRegisterExportRow>
     */
    private static function export(Course $course, ?CourseRegisterExport $exporter = null): array
    {
        return ($exporter ?? self::exporter())->forCourse($course->getTeacher(), $course->getId(), new \DateTimeImmutable(self::NOW));
    }

    /**
     * @param list<CourseRegisterExportRow> $rows
     *
     * @return list<array{string, string, string|null}>
     */
    private static function summary(array $rows): array
    {
        return array_map(static fn (CourseRegisterExportRow $row): array => [
            $row->startsAtUtc->format('Y-m-d H:i'),
            $row->lastName,
            $row->status?->value,
        ], $rows);
    }

    private static function exporter(): CourseRegisterExport
    {
        return new CourseRegisterExport(self::connection());
    }
}
