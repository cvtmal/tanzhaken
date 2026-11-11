<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Form\ConfirmActionType;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\Courses\CoursePages;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The writes of the lesson page (REG-2, #13): present / absent / clear through the row form with
 * a reason (P1, R4), the 303 back to the row (R12) with its flash (P7), the balance (REG-4), the
 * refusals (R8, R5), Complete (R7) and Cancel through #10's route (R8, P2).
 */
#[ResetDatabase]
final class LessonMarkingTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;

    private KernelBrowser $client;
    private Teacher $teacher;
    private Course $course;
    private LessonSession $lesson;
    private Student $alex;

    protected function setUp(): void
    {
        self::mockTime('2026-11-10 19:30:00');
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
        $this->course = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Salsa Beginners']);
        $this->lesson = self::lessonAt($this->course, '2026-11-10 18:00:00');
        $this->alex = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        $enrollment = EnrollmentFactory::createOne(['course' => $this->course, 'student' => $this->alex]);
        CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 10]);
    }

    public function test_present_redirects_to_the_row_and_uses_one_lesson(): void
    {
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));
        self::assertSame('10', $this->chip($crawler));

        $this->client->submit(self::rowForm($crawler, $this->alex, 'Present'));

        $crawler = self::followToFlash($this->client, self::rowTarget($this->lesson, $this->alex), 'success', 'Alex M. marked present');
        self::assertSame('present', self::storedStatus($this->client, $this->lesson, $this->alex));
        self::assertSame('9', $this->chip($crawler));
        self::assertSelectorTextSame('#lesson-present-count', '1 of 1 present');
        self::assertStringContainsString('register-row--present', (string) self::row($crawler, $this->alex)->attr('class'));
        $marked = self::auditDetails($this->client, 'attendance.marked');
        self::assertCount(1, $marked);
        self::assertSame('present', $marked[0]['status']);
        self::assertNull($marked[0]['previous_status']);
    }

    public function test_absent_leaves_the_balance_unchanged(): void
    {
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        $this->client->submit(self::rowForm($crawler, $this->alex, 'Absent'));

        $crawler = self::followToFlash($this->client, self::rowTarget($this->lesson, $this->alex), 'success', 'Alex M. marked absent');
        self::assertSame('absent', self::storedStatus($this->client, $this->lesson, $this->alex));
        self::assertSame('10', $this->chip($crawler));
        self::assertSelectorTextSame('#lesson-present-count', '0 of 1 present');
    }

    public function test_clear_with_a_reason_deletes_the_row_and_audits_the_reason(): void
    {
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $this->alex]);
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));
        self::assertSame('9', $this->chip($crawler));

        $this->client->submit(self::rowForm($crawler, $this->alex, 'Clear mark'), [self::reasonField($this->alex) => 'Wrong student']);

        $crawler = self::followToFlash($this->client, self::rowTarget($this->lesson, $this->alex), 'success', 'Mark of Alex M. cleared');
        self::assertNull(self::storedStatus($this->client, $this->lesson, $this->alex));
        self::assertSame('10', $this->chip($crawler));
        $cleared = self::auditDetails($this->client, 'attendance.cleared');
        self::assertCount(1, $cleared);
        self::assertSame('Wrong student', $cleared[0]['reason']);
        self::assertSame('present', $cleared[0]['previous_status']);
    }

    public function test_a_reason_of_256_characters_is_refused_and_nothing_is_written(): void
    {
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));
        $audits = self::countRows($this->client, 'audit_event');

        $this->client->submit(self::rowForm($crawler, $this->alex, 'Present'), [self::reasonField($this->alex) => str_repeat('a', 256)]);

        self::followToFlash($this->client, self::rowTarget($this->lesson, $this->alex), 'error', 'The reason may have at most 255 characters.');
        self::assertNull(self::storedStatus($this->client, $this->lesson, $this->alex));
        self::assertSame($audits, self::countRows($this->client, 'audit_event'));
    }

    public function test_marking_on_a_cancelled_lesson_is_refused(): void
    {
        $lesson = LessonSessionFactory::new()->cancelled()->create(['course' => $this->course]);
        $sam = $this->enrolled('Sam', 'Keller');
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $sam, 'status' => AttendanceStatus::Absent]);
        $crawler = $this->client->request('GET', self::lessonPath($lesson));
        $audits = self::countRows($this->client, 'audit_event');

        // The page offers no Present button; a stale or crafted POST is refused by the marker.
        self::postMark($this->client, $crawler, $lesson, $this->alex, 'present');

        self::followToFlash($this->client, self::rowTarget($lesson, $this->alex), 'error', 'This lesson is cancelled. Attendance cannot be marked.');
        self::assertNull(self::storedStatus($this->client, $lesson, $this->alex));
        self::assertSame($audits, self::countRows($this->client, 'audit_event'));
    }

    public function test_marking_a_student_who_left_is_refused(): void
    {
        $nina = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Nina', 'lastName' => 'Albrecht']);
        EnrollmentFactory::new()->left()->create(['course' => $this->course, 'student' => $nina]);
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $nina, 'status' => AttendanceStatus::Absent]);
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));
        $audits = self::countRows($this->client, 'audit_event');

        self::postMark($this->client, $crawler, $this->lesson, $nina, 'present');

        self::followToFlash($this->client, self::rowTarget($this->lesson, $nina), 'error', 'This student is no longer enrolled in this course.');
        self::assertSame('absent', self::storedStatus($this->client, $this->lesson, $nina));
        self::assertSame($audits, self::countRows($this->client, 'audit_event'));
    }

    public function test_complete_marks_the_lesson_completed(): void
    {
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        $this->client->submit($crawler->filter('form.lesson-page__complete')->selectButton('Complete lesson')->form());

        self::followToFlash($this->client, self::lessonPath($this->lesson), 'success', 'Lesson completed.');
        self::assertSelectorTextContains('.lesson-page', 'Completed');
        self::assertSelectorNotExists('form.lesson-page__complete');
        self::assertSame('completed', $this->lessonStatus($this->lesson));
        self::assertCount(1, self::auditDetails($this->client, 'lesson.completed'));
    }

    public function test_cancel_through_the_lesson_page_cancels_and_returns_to_the_course(): void
    {
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        $this->client->submit($crawler->filter('form.lesson-cancel__form')->selectButton('Cancel lesson')->form(), [
            ConfirmActionType::formName('cancel', $this->lesson->getId()).'[reason]' => 'Studio closed',
        ]);

        self::assertResponseRedirects(self::coursePath($this->course->getId()), 303);
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-region .toast');
        self::assertSame('cancelled', $this->lessonStatus($this->lesson));
        self::assertCount(1, self::auditDetails($this->client, 'lesson.cancelled'));
    }

    private function enrolled(string $firstName, string $lastName): Student
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => $firstName, 'lastName' => $lastName]);
        EnrollmentFactory::createOne(['course' => $this->course, 'student' => $student]);

        return $student;
    }

    private function chip(Crawler $crawler): string
    {
        return self::row($crawler, $this->alex)->filter('.register-row__balance .balance__chip')->text();
    }

    private function lessonStatus(LessonSession $lesson): string
    {
        return (string) self::db($this->client)->fetchOne('SELECT status FROM lesson_session WHERE id = ?', [$lesson->getId()->toBinary()]);
    }
}
