<?php

declare(strict_types=1);

namespace App\Tests\Integration\CheckIn;

use App\CheckIn\Exception\CheckInNotFound;
use App\CheckIn\Exception\InvalidCheckInWindow;
use App\CheckIn\Service\CheckInRefusal;
use App\CheckIn\Service\ValidCheckIn;
use App\Courses\Entity\LessonSession;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CheckInWindowsTest extends KernelTestCase
{
    use BuildsCheckInServices;
    use ClockSensitiveTrait;

    public function test_open_completes_the_lesson_and_opens_one_window_for_sixty_minutes(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        [$teacher, $lesson] = self::openLesson();

        $opened = self::windows()->open($teacher, $lesson->getId());

        self::assertTrue($opened->lessonId->equals($lesson->getId()));
        self::assertSame('2026-03-10 20:00:00 UTC', $opened->expiresAt->format('Y-m-d H:i:s T'));
        self::assertSame('completed', self::lessonStatus($lesson));
        $rows = self::connection()->fetchAllAssociative('SELECT * FROM check_in_window');
        self::assertCount(1, $rows);
        self::assertTrue(Uuid::fromBinary((string) $rows[0]['id'])->equals($opened->windowId));
        self::assertTrue(Uuid::fromBinary((string) $rows[0]['lesson_session_id'])->equals($lesson->getId()));
        self::assertSame('2026-03-10 19:00:00', $rows[0]['opened_at']);
        self::assertSame('2026-03-10 20:00:00', $rows[0]['expires_at']);
        self::assertNull($rows[0]['closed_at']);
        self::assertSame([[
            'lesson_id' => $lesson->getId()->toRfc4122(),
            'expires_at' => '2026-03-10T20:00:00+00:00',
            'closed_windows' => 0,
        ]], self::auditDetails('checkin.opened'));
        self::assertCount(1, self::auditDetails('lesson.completed'));
        $audit = self::connection()->fetchAssociative("SELECT teacher_id, actor_type, entity_type, entity_id FROM audit_event WHERE action = 'checkin.opened'");
        self::assertIsArray($audit);
        self::assertTrue(Uuid::fromBinary((string) $audit['teacher_id'])->equals($teacher->getId()));
        self::assertSame('teacher', $audit['actor_type']);
        self::assertSame('check_in_window', $audit['entity_type']);
        self::assertTrue(Uuid::fromBinary((string) $audit['entity_id'])->equals($opened->windowId));
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        self::bootKernel();
        [$teacher, $lesson] = self::openLesson();

        $opened = self::windows()->open($teacher, $lesson->getId());

        $hash = self::connection()->fetchOne('SELECT token_hash FROM check_in_window');
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame(hash('sha256', $opened->token), $hash);
        foreach (['check_in_window', 'audit_event'] as $table) {
            foreach (self::connection()->fetchAllAssociative(\sprintf('SELECT * FROM %s', $table)) as $row) {
                foreach ($row as $column => $value) {
                    self::assertStringNotContainsString($opened->token, (string) $value, \sprintf('%s.%s', $table, $column));
                }
            }
        }
    }

    public function test_open_on_a_completed_lesson_writes_no_second_completion(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $lesson = LessonSessionFactory::new()->completed()->create(['course' => CourseFactory::createOne(['teacher' => $teacher])]);

        $opened = self::windows()->open($teacher, $lesson->getId());

        self::assertInstanceOf(ValidCheckIn::class, self::windows()->resolve($opened->token));
        self::assertSame('completed', self::lessonStatus($lesson));
        self::assertSame([], self::auditDetails('lesson.completed'));
        self::assertCount(1, self::auditDetails('checkin.opened'));
    }

    public function test_reopen_rotates_the_token_and_closes_the_previous_window(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        [$teacher, $lesson] = self::openLesson();
        $first = self::windows()->open($teacher, $lesson->getId());
        self::mockTime('2026-03-10 19:10:00');

        $second = self::windows()->open($teacher, $lesson->getId());

        self::assertNotSame($first->token, $second->token);
        self::assertFalse($first->windowId->equals($second->windowId));
        self::assertSame('2026-03-10 19:10:00', self::closedAt($first->windowId));
        self::assertNull(self::closedAt($second->windowId));
        self::assertSame(1, (int) self::connection()->fetchOne('SELECT COUNT(*) FROM check_in_window WHERE closed_at IS NULL'));
        self::assertSame(CheckInRefusal::Closed, self::windows()->resolve($first->token));
        self::assertInstanceOf(ValidCheckIn::class, self::windows()->resolve($second->token));
        self::assertSame([0, 1], array_column(self::auditDetails('checkin.opened'), 'closed_windows'));
        self::assertCount(1, self::auditDetails('lesson.completed'));
    }

    public function test_reopen_after_expiry_also_closes_the_expired_window(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        [$teacher, $lesson] = self::openLesson();
        $first = self::windows()->open($teacher, $lesson->getId());
        self::mockTime('2026-03-10 21:00:00');

        self::windows()->open($teacher, $lesson->getId());

        self::assertSame('2026-03-10 21:00:00', self::closedAt($first->windowId));
        self::assertSame(1, (int) self::connection()->fetchOne('SELECT COUNT(*) FROM check_in_window WHERE closed_at IS NULL'));
        self::assertSame([0, 1], array_column(self::auditDetails('checkin.opened'), 'closed_windows'));
    }

    public function test_close_closes_the_active_window_once(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        [$teacher, $lesson] = self::openLesson();
        $opened = self::windows()->open($teacher, $lesson->getId());
        self::mockTime('2026-03-10 19:20:00');

        self::assertTrue(self::windows()->close($teacher, $lesson->getId()));

        self::assertSame('2026-03-10 19:20:00', self::closedAt($opened->windowId));
        self::assertSame(CheckInRefusal::Closed, self::windows()->resolve($opened->token));
        self::assertSame([['lesson_id' => $lesson->getId()->toRfc4122()]], self::auditDetails('checkin.closed'));
        $entityId = self::connection()->fetchOne("SELECT entity_id FROM audit_event WHERE action = 'checkin.closed'");
        self::assertTrue(Uuid::fromBinary((string) $entityId)->equals($opened->windowId));
        $audits = self::countRows('audit_event');

        self::assertFalse(self::windows()->close($teacher, $lesson->getId()));
        self::assertSame($audits, self::countRows('audit_event'));
    }

    public function test_close_after_expiry_changes_nothing(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        [$teacher, $lesson] = self::openLesson();
        $opened = self::windows()->open($teacher, $lesson->getId());
        $audits = self::countRows('audit_event');
        self::mockTime('2026-03-10 20:00:00');

        self::assertFalse(self::windows()->close($teacher, $lesson->getId()));

        self::assertNull(self::closedAt($opened->windowId));
        self::assertSame($audits, self::countRows('audit_event'));
    }

    public function test_close_without_a_window_returns_false(): void
    {
        self::bootKernel();
        [$teacher, $lesson] = self::openLesson();

        self::assertFalse(self::windows()->close($teacher, $lesson->getId()));
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_resolve_is_valid_until_the_second_before_expiry(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 19:00:00');
        [$teacher, $lesson] = self::openLesson();
        $course = $lesson->getCourse();
        $opened = self::windows()->open($teacher, $lesson->getId());
        self::mockTime('2026-03-10 19:59:59');

        $valid = self::windows()->resolve($opened->token);

        self::assertInstanceOf(ValidCheckIn::class, $valid);
        self::assertTrue($valid->windowId->equals($opened->windowId));
        self::assertTrue($valid->lessonId->equals($lesson->getId()));
        self::assertEquals($lesson->getStartsAtUtc(), $valid->lessonStartsAtUtc);
        self::assertEquals($lesson->getEndsAtUtc(), $valid->lessonEndsAtUtc);
        self::assertTrue($valid->courseId->equals($course->getId()));
        self::assertSame($course->getName(), $valid->courseName);
        self::assertSame($course->getTimezone(), $valid->courseTimezone);
        self::assertTrue($valid->teacherId->equals($teacher->getId()));
        self::assertSame($teacher->getName(), $valid->teacherName);
        self::assertSame('2026-03-10 20:00:00 UTC', $valid->expiresAt->format('Y-m-d H:i:s T'));

        self::mockTime('2026-03-10 20:00:00');
        self::assertSame(CheckInRefusal::Expired, self::windows()->resolve($opened->token));
    }

    public function test_malformed_and_unknown_tokens_are_refused(): void
    {
        self::bootKernel();
        $windows = self::windows();

        self::assertSame(CheckInRefusal::Malformed, $windows->resolve('abc'));
        self::assertSame(CheckInRefusal::Malformed, $windows->resolve(str_repeat('a', 44)));
        self::assertSame(CheckInRefusal::Malformed, $windows->resolve(str_repeat('a', 42).'+'));
        self::assertSame(CheckInRefusal::Unknown, $windows->resolve(str_repeat('a', 43)));
    }

    public function test_lesson_cancelled_after_opening_is_refused(): void
    {
        self::bootKernel();
        [$teacher, $lesson] = self::openLesson();
        $opened = self::windows()->open($teacher, $lesson->getId());
        self::connection()->executeStatement(
            "UPDATE lesson_session SET status = 'cancelled', cancelled_at = '2026-03-10 19:30:00' WHERE id = ?",
            [$lesson->getId()],
            [UuidType::NAME],
        );

        self::assertSame(CheckInRefusal::LessonCancelled, self::windows()->resolve($opened->token));
    }

    public function test_course_archived_after_opening_is_refused(): void
    {
        self::bootKernel();
        [$teacher, $lesson] = self::openLesson();
        $opened = self::windows()->open($teacher, $lesson->getId());
        self::connection()->executeStatement(
            "UPDATE course SET archived_at = '2026-03-10 19:30:00' WHERE id = ?",
            [$lesson->getCourse()->getId()],
            [UuidType::NAME],
        );

        self::assertSame(CheckInRefusal::CourseArchived, self::windows()->resolve($opened->token));
    }

    public function test_open_on_a_cancelled_lesson_is_refused_and_changes_nothing(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $lesson = LessonSessionFactory::new()->cancelled()->create(['course' => CourseFactory::createOne(['teacher' => $teacher])]);

        self::assertRefused(InvalidCheckInWindow::lessonCancelled(), static fn () => self::windows()->open($teacher, $lesson->getId()));

        self::assertSame('cancelled', self::lessonStatus($lesson));
        self::assertSame(0, self::countRows('check_in_window'));
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_open_on_an_archived_course_is_refused_and_changes_nothing(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $lesson = LessonSessionFactory::createOne(['course' => CourseFactory::new()->archived()->create(['teacher' => $teacher])]);

        self::assertRefused(InvalidCheckInWindow::courseArchived(), static fn () => self::windows()->open($teacher, $lesson->getId()));

        self::assertSame('scheduled', self::lessonStatus($lesson));
        self::assertSame(0, self::countRows('check_in_window'));
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_foreign_and_unknown_lessons_are_not_found(): void
    {
        self::bootKernel();
        [$teacher, $lesson] = self::openLesson();
        $other = TeacherFactory::createOne();
        $unknown = Uuid::v7();

        self::assertRefused(CheckInNotFound::lesson($lesson->getId()), static fn () => self::windows()->open($other, $lesson->getId()));
        self::assertRefused(CheckInNotFound::lesson($lesson->getId()), static fn () => self::windows()->close($other, $lesson->getId()));
        self::assertRefused(CheckInNotFound::lesson($unknown), static fn () => self::windows()->open($teacher, $unknown));
        self::assertRefused(CheckInNotFound::lesson($unknown), static fn () => self::windows()->close($teacher, $unknown));

        self::assertSame('scheduled', self::lessonStatus($lesson));
        self::assertSame(0, self::countRows('check_in_window'));
        self::assertSame(0, self::countRows('audit_event'));
    }

    /**
     * Asserts that the call throws an exception of the expected class and message, and that the
     * EntityManager stays open (refusals come before the transaction, D-027).
     */
    private static function assertRefused(\Throwable $expected, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $actual) {
            self::assertSame($expected::class, $actual::class, $actual->getMessage());
            self::assertSame($expected->getMessage(), $actual->getMessage());
            self::assertTrue(self::entityManager()->isOpen());

            return;
        }

        self::fail(\sprintf('Expected %s: %s', $expected::class, $expected->getMessage()));
    }

    private static function lessonStatus(LessonSession $lesson): mixed
    {
        return self::connection()->fetchOne('SELECT status FROM lesson_session WHERE id = ?', [$lesson->getId()], [UuidType::NAME]);
    }

    private static function closedAt(Uuid $windowId): mixed
    {
        return self::connection()->fetchOne('SELECT closed_at FROM check_in_window WHERE id = ?', [$windowId], [UuidType::NAME]);
    }
}
