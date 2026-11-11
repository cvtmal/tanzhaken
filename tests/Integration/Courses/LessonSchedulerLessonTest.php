<?php

declare(strict_types=1);

namespace App\Tests\Integration\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Service\LessonScheduler;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\TeacherFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Adding, re-timing, cancelling, completing and reinstating single lessons. Today is Sunday
 * 2026-03-01; the courses run on Tuesdays at 19:00 Zurich time (18:00 UTC in winter).
 */
#[ResetDatabase]
final class LessonSchedulerLessonTest extends KernelTestCase
{
    use BuildsLessonScheduler;
    use ClockSensitiveTrait;

    public function test_add_lesson_creates_one_lesson_at_the_local_time_with_the_course_duration(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $scheduler = self::scheduler();

        $lesson = $scheduler->addLesson($course->getTeacher(), $course->getId(), new \DateTimeImmutable('2026-03-05 18:30:00'));

        self::assertSame('2026-03-05 17:30:00', $lesson->getStartsAtUtc()->format('Y-m-d H:i:s'));
        self::assertSame(
            [['startsAtUtc' => '2026-03-05 17:30:00', 'endsAtUtc' => '2026-03-05 18:30:00', 'status' => 'scheduled']],
            self::lessonRows($course),
        );
        self::assertSame(
            [['startsAtUtc' => '2026-03-05T17:30:00+00:00', 'endsAtUtc' => '2026-03-05T18:30:00+00:00']],
            self::auditDetails('lesson.added'),
        );

        $scheduler->addLesson($course->getTeacher(), $course->getId(), new \DateTimeImmutable('2026-04-02 10:00:00'), 90);

        self::assertSame(
            ['startsAtUtc' => '2026-04-02 08:00:00', 'endsAtUtc' => '2026-04-02 09:30:00', 'status' => 'scheduled'],
            self::lessonRows($course)[1],
        );
        self::assertCount(2, self::auditDetails('lesson.added'));
    }

    public function test_add_lesson_refuses_a_taken_slot_including_a_cancelled_one(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $teacher = $course->getTeacher();
        $scheduler = self::scheduler();
        $scheduler->addLesson($teacher, $course->getId(), new \DateTimeImmutable('2026-03-05 18:30:00'));
        LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-03-12 17:30:00'));

        self::assertRefused(
            InvalidCourse::lessonTimeTaken(),
            static fn () => $scheduler->addLesson($teacher, $course->getId(), new \DateTimeImmutable('2026-03-05 18:30:00'), 30),
        );
        self::assertRefused(
            InvalidCourse::lessonTimeTaken(),
            static fn () => $scheduler->addLesson($teacher, $course->getId(), new \DateTimeImmutable('2026-03-12 18:30:00')),
        );

        self::assertSame(['2026-03-05 17:30:00', '2026-03-12 17:30:00'], self::lessonStarts($course));
        self::assertCount(1, self::auditDetails('lesson.added'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    public function test_reschedule_moves_one_lesson_and_leaves_the_others(): void
    {
        self::boot();
        $course = self::zurichCourse();
        self::lesson($course, '2026-03-03 18:00:00');
        $moved = self::lesson($course, '2026-03-10 18:00:00');
        self::lesson($course, '2026-03-17 18:00:00');

        self::scheduler()->reschedule($course->getTeacher(), $moved->getId(), new \DateTimeImmutable('2026-03-11 20:00:00'));

        self::assertSame(
            ['2026-03-03 18:00:00', '2026-03-11 19:00:00', '2026-03-17 18:00:00'],
            self::lessonStarts($course),
        );
        self::assertSame(
            [['fromStartsAtUtc' => '2026-03-10T18:00:00+00:00', 'toStartsAtUtc' => '2026-03-11T19:00:00+00:00', 'durationMinutes' => 60]],
            self::auditDetails('lesson.rescheduled'),
        );
    }

    public function test_reschedule_keeps_the_duration_when_none_is_given(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00', 90);
        $scheduler = self::scheduler();

        $scheduler->reschedule($course->getTeacher(), $lesson->getId(), new \DateTimeImmutable('2026-03-04 19:00:00'));

        self::assertSame(
            [['startsAtUtc' => '2026-03-04 18:00:00', 'endsAtUtc' => '2026-03-04 19:30:00', 'status' => 'scheduled']],
            self::lessonRows($course),
        );

        $scheduler->reschedule($course->getTeacher(), $lesson->getId(), new \DateTimeImmutable('2026-03-04 19:00:00'), 45);

        self::assertSame('2026-03-04 18:45:00', self::lessonRows($course)[0]['endsAtUtc']);
        self::assertSame([90, 45], array_column(self::auditDetails('lesson.rescheduled'), 'durationMinutes'));
    }

    public function test_reschedule_onto_a_taken_slot_is_refused(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00');
        self::lesson($course, '2026-03-10 18:00:00');
        $scheduler = self::scheduler();

        self::assertRefused(
            InvalidCourse::lessonTimeTaken(),
            static fn () => $scheduler->reschedule($course->getTeacher(), $lesson->getId(), new \DateTimeImmutable('2026-03-10 19:00:00')),
        );

        self::assertSame(['2026-03-03 18:00:00', '2026-03-10 18:00:00'], self::lessonStarts($course));
        self::assertSame('2026-03-03 18:00:00', $lesson->getStartsAtUtc()->format('Y-m-d H:i:s'));
        self::assertSame([], self::auditDetails('lesson.rescheduled'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    public function test_reschedule_to_the_same_time_writes_no_audit_row(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00');
        $scheduler = self::scheduler();

        $scheduler->reschedule($course->getTeacher(), $lesson->getId(), new \DateTimeImmutable('2026-03-03 19:00:00'));
        $scheduler->reschedule($course->getTeacher(), $lesson->getId(), new \DateTimeImmutable('2026-03-03 19:00:00'), 60);

        self::assertSame(['2026-03-03 18:00:00'], self::lessonStarts($course));
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_reschedule_refuses_a_cancelled_lesson(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-03-03 18:00:00'));
        $scheduler = self::scheduler();

        self::assertRefused(
            InvalidCourse::cancelledLessonCannotMove(),
            static fn () => $scheduler->reschedule($course->getTeacher(), $lesson->getId(), new \DateTimeImmutable('2026-03-04 19:00:00')),
        );

        self::assertSame(
            [['startsAtUtc' => '2026-03-03 18:00:00', 'endsAtUtc' => '2026-03-03 19:00:00', 'status' => 'cancelled']],
            self::lessonRows($course),
        );
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_cancel_is_refused_while_attendance_exists_and_allowed_after_it_is_removed(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00');
        AttendanceFactory::createOne(['lessonSession' => $lesson]);
        $scheduler = self::scheduler();

        self::assertRefused(
            InvalidCourse::lessonHasAttendance(),
            static fn () => $scheduler->cancel($course->getTeacher(), $lesson->getId(), 'Teacher ill'),
        );

        self::assertSame('scheduled', self::lessonRows($course)[0]['status']);
        self::assertTrue(self::entityManager()->isOpen());
        self::assertSame([], self::auditDetails('lesson.cancelled'));

        self::connection()->executeStatement(
            'DELETE FROM attendance WHERE lesson_session_id = ?',
            [$lesson->getId()],
            [UuidType::NAME],
        );
        $scheduler->cancel($course->getTeacher(), $lesson->getId(), 'Teacher ill');

        self::assertSame('cancelled', self::lessonRows($course)[0]['status']);
        self::assertSame([['reason' => 'Teacher ill']], self::auditDetails('lesson.cancelled'));
    }

    public function test_cancel_trims_the_reason_and_refuses_a_too_long_one(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $first = self::lesson($course, '2026-03-03 18:00:00');
        $second = self::lesson($course, '2026-03-10 18:00:00');
        $scheduler = self::scheduler();

        self::assertRefused(
            InvalidCourse::tooLong('cancellation reason', LessonScheduler::REASON_MAX),
            static fn () => $scheduler->cancel($course->getTeacher(), $first->getId(), str_repeat('x', 256)),
        );
        self::assertSame('scheduled', self::lessonRows($course)[0]['status']);

        $scheduler->cancel($course->getTeacher(), $first->getId(), '  Studio closed  ');
        $scheduler->cancel($course->getTeacher(), $second->getId(), '   ');

        self::assertSame(['cancelled', 'cancelled'], array_column(self::lessonRows($course), 'status'));
        self::assertSame([['reason' => 'Studio closed'], ['reason' => null]], self::auditDetails('lesson.cancelled'));
    }

    public function test_cancel_twice_is_refused(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00');
        $scheduler = self::scheduler();
        $scheduler->cancel($course->getTeacher(), $lesson->getId());

        self::assertRefused(
            InvalidCourse::lessonAlreadyCancelled(),
            static fn () => $scheduler->cancel($course->getTeacher(), $lesson->getId()),
        );

        self::assertSame('cancelled', self::lessonRows($course)[0]['status']);
        self::assertCount(1, self::auditDetails('lesson.cancelled'));
        self::assertTrue(self::entityManager()->isOpen());
    }

    public function test_complete_sets_completed_once_and_is_idempotent(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00');
        $scheduler = self::scheduler();

        $scheduler->complete($course->getTeacher(), $lesson->getId());
        $scheduler->complete($course->getTeacher(), $lesson->getId());

        self::assertSame('completed', self::lessonRows($course)[0]['status']);
        self::assertSame([[]], self::auditDetails('lesson.completed'));
    }

    public function test_complete_refuses_a_cancelled_lesson(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-03-03 18:00:00'));
        $scheduler = self::scheduler();

        self::assertRefused(
            InvalidCourse::cancelledLessonCannotComplete(),
            static fn () => $scheduler->complete($course->getTeacher(), $lesson->getId()),
        );

        self::assertSame('cancelled', self::lessonRows($course)[0]['status']);
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_reinstate_returns_a_cancelled_lesson_to_scheduled(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-03-03 18:00:00'));

        self::scheduler()->reinstate($course->getTeacher(), $lesson->getId());

        self::assertSame('scheduled', self::lessonRows($course)[0]['status']);
        self::assertNull(self::connection()->fetchOne(
            'SELECT cancelled_at FROM lesson_session WHERE id = ?',
            [$lesson->getId()],
            [UuidType::NAME],
        ));
        self::assertSame([[]], self::auditDetails('lesson.reinstated'));
    }

    public function test_reinstate_refuses_a_scheduled_lesson(): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00');
        $scheduler = self::scheduler();

        self::assertRefused(
            InvalidCourse::lessonNotCancelled(),
            static fn () => $scheduler->reinstate($course->getTeacher(), $lesson->getId()),
        );

        self::assertSame('scheduled', self::lessonRows($course)[0]['status']);
        self::assertSame(0, self::countRows('audit_event'));
    }

    /**
     * @param \Closure(LessonScheduler, Teacher, Course, LessonSession): mixed $call
     */
    #[DataProvider('lessonMethods')]
    public function test_every_lesson_method_refuses_a_lesson_of_an_archived_course(\Closure $call): void
    {
        self::boot();
        $course = self::zurichCourse(archived: true);
        $lesson = LessonSessionFactory::new()->cancelled()->create(self::times($course, '2026-03-03 18:00:00'));
        $scheduler = self::scheduler();

        self::assertRefused(InvalidCourse::courseArchived(), static fn () => $call($scheduler, $course->getTeacher(), $course, $lesson));

        self::assertSame(
            [['startsAtUtc' => '2026-03-03 18:00:00', 'endsAtUtc' => '2026-03-03 19:00:00', 'status' => 'cancelled']],
            self::lessonRows($course),
        );
        self::assertSame(0, self::countRows('audit_event'));
    }

    /**
     * @return iterable<string, array{\Closure(LessonScheduler, Teacher, Course, LessonSession): mixed}>
     */
    public static function lessonMethods(): iterable
    {
        yield 'addLesson' => [static fn (LessonScheduler $s, Teacher $t, Course $c, LessonSession $l) => $s->addLesson($t, $c->getId(), new \DateTimeImmutable('2026-03-05 18:30:00'))];
        yield 'reschedule' => [static fn (LessonScheduler $s, Teacher $t, Course $c, LessonSession $l) => $s->reschedule($t, $l->getId(), new \DateTimeImmutable('2026-03-05 18:30:00'))];
        yield 'cancel' => [static fn (LessonScheduler $s, Teacher $t, Course $c, LessonSession $l) => $s->cancel($t, $l->getId())];
        yield 'complete' => [static fn (LessonScheduler $s, Teacher $t, Course $c, LessonSession $l) => $s->complete($t, $l->getId())];
        yield 'reinstate' => [static fn (LessonScheduler $s, Teacher $t, Course $c, LessonSession $l) => $s->reinstate($t, $l->getId())];
    }

    /**
     * @param 'course'|'lesson'                               $kind
     * @param \Closure(LessonScheduler, Teacher, Uuid): mixed $call
     */
    #[DataProvider('foreignIdMethods')]
    public function test_every_method_answers_not_found_for_another_teachers_ids(string $kind, \Closure $call): void
    {
        self::boot();
        $course = self::zurichCourse();
        $lesson = self::lesson($course, '2026-03-03 18:00:00');
        $otherTeacher = TeacherFactory::createOne();
        $scheduler = self::scheduler();
        $foreignId = 'course' === $kind ? $course->getId() : $lesson->getId();
        $unknownId = Uuid::v7();

        foreach ([$foreignId, $unknownId] as $id) {
            self::assertRefused(
                'course' === $kind ? NotFound::course($id) : NotFound::lesson($id),
                static fn () => $call($scheduler, $otherTeacher, $id),
            );
        }
        self::assertRefused(
            'course' === $kind ? NotFound::course($unknownId) : NotFound::lesson($unknownId),
            static fn () => $call($scheduler, $course->getTeacher(), $unknownId),
        );

        self::assertSame(
            [['startsAtUtc' => '2026-03-03 18:00:00', 'endsAtUtc' => '2026-03-03 19:00:00', 'status' => 'scheduled']],
            self::lessonRows($course),
        );
        self::assertSame(0, self::countRows('audit_event'));
    }

    /**
     * @return iterable<string, array{'course'|'lesson', \Closure(LessonScheduler, Teacher, Uuid): mixed}>
     */
    public static function foreignIdMethods(): iterable
    {
        yield 'addLesson' => ['course', static fn (LessonScheduler $s, Teacher $t, Uuid $id) => $s->addLesson($t, $id, new \DateTimeImmutable('2026-03-05 18:30:00'))];
        yield 'reschedule' => ['lesson', static fn (LessonScheduler $s, Teacher $t, Uuid $id) => $s->reschedule($t, $id, new \DateTimeImmutable('2026-03-05 18:30:00'))];
        yield 'cancel' => ['lesson', static fn (LessonScheduler $s, Teacher $t, Uuid $id) => $s->cancel($t, $id)];
        yield 'complete' => ['lesson', static fn (LessonScheduler $s, Teacher $t, Uuid $id) => $s->complete($t, $id)];
        yield 'reinstate' => ['lesson', static fn (LessonScheduler $s, Teacher $t, Uuid $id) => $s->reinstate($t, $id)];
    }

    private static function boot(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-01 10:00:00');
    }

    private static function lesson(Course $course, string $startsAtUtc, int $minutes = 60): LessonSession
    {
        return LessonSessionFactory::createOne(self::times($course, $startsAtUtc, $minutes));
    }

    /**
     * @return array{course: Course, startsAtUtc: \DateTimeImmutable, endsAtUtc: \DateTimeImmutable}
     */
    private static function times(Course $course, string $startsAtUtc, int $minutes = 60): array
    {
        $start = new \DateTimeImmutable($startsAtUtc, new \DateTimeZone('UTC'));

        return ['course' => $course, 'startsAtUtc' => $start, 'endsAtUtc' => $start->modify('+'.$minutes.' minutes')];
    }
}
