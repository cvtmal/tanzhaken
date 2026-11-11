<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Form\ConfirmActionType;
use App\Students\Entity\Student;
use App\Tests\Factory\LessonSessionFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;

/**
 * Helpers for the lesson page tests (#13), next to #10's `CoursePages` (P8): paths, the register
 * row of a student, its form with one button clicked, a crafted mark POST, audit details. Use in a
 * WebTestCase after `createClient()`.
 */
trait LessonPages
{
    private static function lessonAt(Course $course, string $startsAtUtc): LessonSession
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc, new \DateTimeZone('UTC'));

        return LessonSessionFactory::createOne(['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')]);
    }

    private static function lessonPath(LessonSession $lesson, string $suffix = ''): string
    {
        return '/courses/'.$lesson->getCourse()->getId()->toRfc4122().'/lessons/'.$lesson->getId()->toRfc4122().$suffix;
    }

    private static function markPath(LessonSession $lesson, Student $student): string
    {
        return self::lessonPath($lesson, '/attendance/'.$student->getId()->toRfc4122());
    }

    /**
     * Where a row action redirects to: the lesson page at the student's row (R12).
     */
    private static function rowTarget(LessonSession $lesson, Student $student): string
    {
        return self::lessonPath($lesson).'#student-'.$student->getId()->toRfc4122();
    }

    private static function row(Crawler $crawler, Student $student): Crawler
    {
        $row = $crawler->filter('ul.lesson-register > li#student-'.$student->getId()->toRfc4122());
        self::assertCount(1, $row, \sprintf('One register row for %s.', $student->getFirstName()));

        return $row;
    }

    /**
     * The student's row form, submitted with the button labelled `$button` ("Present", "Absent", "Clear mark").
     */
    private static function rowForm(Crawler $crawler, Student $student, string $button): Form
    {
        $buttons = self::row($crawler, $student)->selectButton($button);
        self::assertCount(1, $buttons, \sprintf('One "%s" button in the row of %s.', $button, $student->getFirstName()));

        return $buttons->form();
    }

    private static function reasonField(Student $student): string
    {
        return ConfirmActionType::formName('mark', $student->getId()).'[reason]';
    }

    /**
     * Posts the mark form of `$student` without the page offering it (a stale or crafted request),
     * with the stateless token of any form on `$crawler` and `$button` clicked.
     */
    private static function postMark(KernelBrowser $client, Crawler $crawler, LessonSession $lesson, Student $student, string $button, string $reason = ''): void
    {
        $token = (string) $crawler->filter('input[name$="[_token]"]')->first()->attr('value');
        self::assertNotSame('', $token);

        $client->request('POST', self::markPath($lesson, $student), [
            ConfirmActionType::formName('mark', $student->getId()) => [$button => '', 'reason' => $reason, '_token' => $token],
        ]);
    }

    /**
     * The present / absent status of the attendance row, or null without one.
     */
    private static function storedStatus(KernelBrowser $client, LessonSession $lesson, Student $student): ?string
    {
        $status = self::db($client)->fetchOne(
            'SELECT status FROM attendance WHERE lesson_session_id = ? AND student_id = ?',
            [$lesson->getId()->toBinary(), $student->getId()->toBinary()],
        );

        return false === $status ? null : (string) $status;
    }

    /**
     * The details of every audit event with `$action`, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function auditDetails(KernelBrowser $client, string $action): array
    {
        $rows = self::db($client)->fetchFirstColumn('SELECT details_json FROM audit_event WHERE action = ? ORDER BY occurred_at, rowid', [$action]);

        return array_map(static function (mixed $json): array {
            $details = json_decode((string) $json, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($details);

            /* @var array<string, mixed> $details */
            return $details;
        }, $rows);
    }

    /**
     * Text with ICU's narrow and plain no-break spaces as spaces ("7:00 PM").
     */
    private static function plain(string $text): string
    {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', $text);
    }
}
