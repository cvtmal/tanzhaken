<?php

declare(strict_types=1);

namespace App\Tests\Integration\Credits;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Credits\ReadModel\Balances;
use App\Credits\ReadModel\EnrollmentBalance;
use App\Credits\ReadModel\RenewalState;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use App\Tests\Story\MainStory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class BalancesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-14 09:00:00');
    }

    public function test_prd_example_sequence(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        $teacher = $enrollment->getCourse()->getTeacher();
        CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 10]);

        self::attend($enrollment, 9);
        $balance = self::balance($teacher, $enrollment);
        self::assertSame([10, 9, 1, RenewalState::Low], [$balance->credits, $balance->used, $balance->balance, $balance->state]);

        self::attend($enrollment, 1);
        $balance = self::balance($teacher, $enrollment);
        self::assertSame([0, RenewalState::RenewalNeeded, 0], [$balance->balance, $balance->state, $balance->outstanding()]);

        self::attend($enrollment, 1);
        $balance = self::balance($teacher, $enrollment);
        self::assertSame([-1, RenewalState::RenewalNeeded, 1], [$balance->balance, $balance->state, $balance->outstanding()]);

        CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 10]);
        $balance = self::balance($teacher, $enrollment);
        self::assertSame([20, 11, 9, RenewalState::Ok], [$balance->credits, $balance->used, $balance->balance, $balance->state]);
    }

    public function test_voided_grant_is_excluded(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 10]);
        CreditGrantFactory::new(['enrollment' => $enrollment, 'quantity' => 5])->voided()->create();

        self::assertSame(10, self::balance($enrollment->getCourse()->getTeacher(), $enrollment)->credits);
    }

    public function test_present_at_cancelled_lesson_is_excluded(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        CreditGrantFactory::createOne(['enrollment' => $enrollment]);
        self::attend($enrollment, 2);
        $cancelled = LessonSessionFactory::new(['course' => $enrollment->getCourse()])->cancelled()->create();
        AttendanceFactory::createOne(['lessonSession' => $cancelled, 'student' => $enrollment->getStudent()]);

        $balance = self::balance($enrollment->getCourse()->getTeacher(), $enrollment);
        self::assertSame([2, 8], [$balance->used, $balance->balance]);
    }

    public function test_absent_is_ignored(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        CreditGrantFactory::createOne(['enrollment' => $enrollment]);
        self::attend($enrollment, 1);
        self::attend($enrollment, 3, AttendanceStatus::Absent);

        self::assertSame(9, self::balance($enrollment->getCourse()->getTeacher(), $enrollment)->balance);
    }

    public function test_attendance_in_another_course_is_not_counted(): void
    {
        $student = StudentFactory::createOne();
        $teacher = $student->getTeacher();
        $salsa = EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher]), 'student' => $student]);
        $tango = EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher]), 'student' => $student]);
        CreditGrantFactory::createOne(['enrollment' => $salsa]);
        CreditGrantFactory::createOne(['enrollment' => $tango, 'quantity' => 5]);
        self::attend($tango, 3);

        self::assertSame(10, self::balance($teacher, $salsa)->balance);
        self::assertSame(2, self::balance($teacher, $tango)->balance);
    }

    public function test_enrollment_without_grant_is_zero_and_renewal_needed(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        $teacher = $enrollment->getCourse()->getTeacher();

        $balance = self::balance($teacher, $enrollment);
        self::assertSame([0, 0, 0, RenewalState::RenewalNeeded], [$balance->credits, $balance->used, $balance->balance, $balance->state]);
        self::assertSame([$enrollment->getId()->toRfc4122()], self::ids(self::balances()->renewalNeeded($teacher)));
    }

    public function test_other_teachers_ids_give_nothing(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 1]);
        self::attend($enrollment, 2);
        $stranger = TeacherFactory::createOne();
        $balances = self::balances();

        self::assertNull($balances->forEnrollment($stranger, $enrollment->getId()));
        self::assertSame([], $balances->forCourse($stranger, $enrollment->getCourse()->getId()));
        self::assertSame([], $balances->forStudent($stranger, $enrollment->getStudent()->getId()));
        self::assertSame([], $balances->renewalNeeded($stranger));
    }

    public function test_for_course_includes_left_enrollment_inactive_and_keys_by_id(): void
    {
        $course = CourseFactory::createOne();
        $active = EnrollmentFactory::createOne(['course' => $course]);
        $left = EnrollmentFactory::new(['course' => $course])->left()->create();
        CreditGrantFactory::createOne(['enrollment' => $left, 'quantity' => 4]);

        $rows = self::balances()->forCourse($course->getTeacher(), $course->getId());

        self::assertCount(2, $rows);
        self::assertTrue($rows[$active->getId()->toRfc4122()]->active);
        $leftRow = $rows[$left->getId()->toRfc4122()];
        self::assertFalse($leftRow->active);
        self::assertSame(4, $leftRow->balance);
        self::assertTrue($leftRow->courseId->equals($course->getId()));
        self::assertSame($course->getName(), $leftRow->courseName);
        self::assertTrue($leftRow->studentId->equals($left->getStudent()->getId()));
        self::assertSame($left->getStudent()->getLastName(), $leftRow->studentLastName);
    }

    public function test_for_course_orders_by_last_then_first_name(): void
    {
        $course = CourseFactory::createOne();
        $teacher = $course->getTeacher();
        foreach ([['Lea', 'Weber'], ['Ben', 'Amsler'], ['Ana', 'Weber']] as [$first, $last]) {
            EnrollmentFactory::createOne(['course' => $course, 'student' => StudentFactory::new(['teacher' => $teacher, 'firstName' => $first, 'lastName' => $last])]);
        }

        $names = array_map(
            static fn (EnrollmentBalance $b): string => $b->studentFirstName.' '.$b->studentLastName,
            array_values(self::balances()->forCourse($teacher, $course->getId())),
        );

        self::assertSame(['Ben Amsler', 'Ana Weber', 'Lea Weber'], $names);
    }

    public function test_for_student_lists_all_courses_ordered_by_course_name(): void
    {
        $student = StudentFactory::createOne();
        $teacher = $student->getTeacher();
        EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher, 'name' => 'Tango']), 'student' => $student]);
        EnrollmentFactory::new(['course' => CourseFactory::new(['teacher' => $teacher, 'name' => 'Bachata']), 'student' => $student])->left()->create();
        EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher, 'name' => 'Salsa']), 'student' => $student]);
        EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher, 'name' => 'Ballet'])]);

        $courses = array_map(
            static fn (EnrollmentBalance $b): string => $b->courseName,
            self::balances()->forStudent($teacher, $student->getId()),
        );

        self::assertSame(['Bachata', 'Salsa', 'Tango'], $courses);
    }

    public function test_renewal_needed_lists_only_active_at_or_below_zero(): void
    {
        $teacher = TeacherFactory::createOne();
        $course = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Salsa']);
        $zero = self::enrollmentWithBalance($course, 0);
        $minusTwo = self::enrollmentWithBalance($course, -2);
        self::enrollmentWithBalance($course, 1);
        $left = self::enrollmentWithBalance($course, -1);
        $left->leave(Clock::get()->now());
        $archivedStudent = self::enrollmentWithBalance($course, -1);
        $archivedStudent->getStudent()->archive(Clock::get()->now());
        $archivedCourse = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Tango']);
        self::enrollmentWithBalance($archivedCourse, -1);
        $archivedCourse->archive(Clock::get()->now());
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();

        $rows = self::balances()->renewalNeeded($teacher);

        self::assertSame([$minusTwo->getId()->toRfc4122(), $zero->getId()->toRfc4122()], self::ids($rows));
        self::assertSame([-2, 0], array_map(static fn (EnrollmentBalance $b): int => $b->balance, $rows));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rosterSizes(): iterable
    {
        yield '3 enrollments' => [3];
        yield '30 enrollments' => [30];
    }

    #[DataProvider('rosterSizes')]
    public function test_query_count_is_constant(int $size): void
    {
        $course = CourseFactory::createOne();
        $lesson = LessonSessionFactory::new(['course' => $course])->completed()->create();
        for ($i = 0; $i < $size; ++$i) {
            $enrollment = EnrollmentFactory::createOne(['course' => $course]);
            CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 0 === $i % 2 ? 1 : 10]);
            AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $enrollment->getStudent()]);
        }
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $balances = self::balances();

        $holder->reset();
        self::assertCount($size, $balances->forCourse($course->getTeacher(), $course->getId()));
        self::assertCount(intdiv($size + 1, 2), $balances->renewalNeeded($course->getTeacher()));

        self::assertCount(2, $holder->getData()['default'] ?? []);
    }

    public function test_seed_balances(): void
    {
        MainStory::load();
        $teacher = TeacherFactory::find(['email' => 'demo@example.com']);

        $byName = [];
        foreach (self::balances()->renewalNeeded($teacher) as $b) {
            $byName[$b->courseName.' / '.$b->studentFirstName.' '.$b->studentLastName] = $b->balance;
        }
        self::assertSame(['Salsa Beginners / Elias Huber' => -1], $byName);

        $salsa = CourseFactory::find(['name' => 'Salsa Beginners']);
        $states = [];
        foreach (self::balances()->forCourse($teacher, $salsa->getId()) as $b) {
            $states[$b->studentFirstName.' '.$b->studentLastName] = [$b->balance, $b->state];
        }
        self::assertSame([2, RenewalState::Low], $states['Noemi Frei']);
        self::assertSame([-1, RenewalState::RenewalNeeded], $states['Elias Huber']);
    }

    private static function balances(): Balances
    {
        // No service uses Balances yet (#12 and #17 will), so the compiled container has removed
        // it; build it from the same connection.
        return new Balances(self::connection());
    }

    private static function balance(Teacher $teacher, Enrollment $enrollment): EnrollmentBalance
    {
        $balance = self::balances()->forEnrollment($teacher, $enrollment->getId());
        self::assertNotNull($balance);

        return $balance;
    }

    /**
     * Adds `$count` attendances of the enrollment's student at new completed lessons of its course.
     */
    private static function attend(Enrollment $enrollment, int $count, AttendanceStatus $status = AttendanceStatus::Present): void
    {
        for ($i = 0; $i < $count; ++$i) {
            AttendanceFactory::createOne([
                'lessonSession' => LessonSessionFactory::new(['course' => $enrollment->getCourse()])->completed()->create(),
                'student' => $enrollment->getStudent(),
                'status' => $status,
            ]);
        }
    }

    private static function enrollmentWithBalance(Course $course, int $balance): Enrollment
    {
        $enrollment = EnrollmentFactory::createOne(['course' => $course]);
        CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 1]);
        self::attend($enrollment, 1 - $balance);

        return $enrollment;
    }

    /**
     * @param iterable<EnrollmentBalance> $rows
     *
     * @return list<string>
     */
    private static function ids(iterable $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->enrollmentId->toRfc4122();
        }

        return $ids;
    }
}
