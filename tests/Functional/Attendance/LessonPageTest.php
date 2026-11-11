<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
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
 * The lesson page (REG-1, #13): the register's rows, order, status, source and balance (R1–R3),
 * left and archived students (R5), the cancelled lesson (R8), the empty register, when Complete
 * and Cancel are offered (R7, R8) and the #16 slot (R10).
 */
#[ResetDatabase]
final class LessonPageTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;

    private KernelBrowser $client;
    private Teacher $teacher;
    private Course $course;
    private LessonSession $lesson;

    protected function setUp(): void
    {
        // Tue 10 Nov 2026, 8:30 PM in Zurich; the lesson started at 7:00 PM there.
        self::mockTime('2026-11-10 19:30:00');
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
        $this->course = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Salsa Beginners', 'location' => 'Studio 2']);
        $this->lesson = self::lessonAt($this->course, '2026-11-10 18:00:00');
    }

    public function test_the_register_lists_the_active_enrollments_by_last_name_with_status_source_and_balance(): void
    {
        $alex = $this->enrolled('Alex', 'Meier', 10);
        $sam = $this->enrolled('Sam', 'Keller', 2);
        $lena = $this->enrolled('Lena', 'Zimmer');
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $alex, 'source' => AttendanceSource::StudentQr]);
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $sam, 'status' => AttendanceStatus::Absent]);
        $left = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Nina', 'lastName' => 'Albrecht']);
        EnrollmentFactory::new()->left()->create(['course' => $this->course, 'student' => $left]);
        StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Otto', 'lastName' => 'Bauer']);

        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Salsa Beginners');
        self::assertStringContainsString('Tue 10 Nov · 7:00 PM–8:00 PM · Studio 2', self::plain($crawler->filter('.lesson-page')->text()));
        self::assertSelectorTextContains('.lesson-page', 'Scheduled');
        self::assertSelectorTextSame('#lesson-present-count', '1 of 3 present');
        self::assertSame(
            ['student-'.$sam->getId()->toRfc4122(), 'student-'.$alex->getId()->toRfc4122(), 'student-'.$lena->getId()->toRfc4122()],
            $crawler->filter('ul.lesson-register > li')->each(static fn (Crawler $row): string => (string) $row->attr('id')),
        );

        self::assertSame('Absent · 8:30 PM · by you', $this->meta($crawler, $sam));
        self::assertSame('Present · 8:30 PM · QR', $this->meta($crawler, $alex));
        self::assertSame('Not marked', $this->meta($crawler, $lena));
        self::assertStringContainsString('register-row--absent', (string) self::row($crawler, $sam)->attr('class'));
        self::assertStringContainsString('register-row--present', (string) self::row($crawler, $alex)->attr('class'));
        self::assertStringContainsString('register-row--not_marked', (string) self::row($crawler, $lena)->attr('class'));

        // Balances' number: Alex 10 − 1 present, Sam 2 (absent costs nothing), Lena 0.
        self::assertSame('9', $this->chip($crawler, $alex));
        self::assertSame('2', $this->chip($crawler, $sam));
        self::assertStringContainsString('Low', self::row($crawler, $sam)->filter('.register-row__balance')->text());
        self::assertSame('0', $this->chip($crawler, $lena));
        self::assertStringContainsString('Renewal needed', self::row($crawler, $lena)->filter('.register-row__balance')->text());

        // Every active row can be marked; the marked ones can also be cleared.
        foreach ([$sam, $alex, $lena] as $student) {
            self::assertCount(1, self::row($crawler, $student)->filter(\sprintf('form.register-row__form[action$="/attendance/%s"]', $student->getId()->toRfc4122())));
            self::assertCount(1, self::row($crawler, $student)->filter('input[name$="[reason]"]'));
            self::assertCount(1, self::row($crawler, $student)->filter('button[name$="[present]"]'));
            self::assertCount(1, self::row($crawler, $student)->filter('button[name$="[absent]"]'));
        }
        self::assertCount(0, self::row($crawler, $lena)->filter('button[name$="[clear]"]'));
        self::assertCount(1, self::row($crawler, $alex)->filter('button[name$="[clear]"]'));
        self::assertStringContainsString('Absent gives the lesson back. Alex would then have 10 left.', self::row($crawler, $alex)->filter('.register-row__cost')->text());
        self::assertStringContainsString('Present uses 1 lesson. Lena would then owe 1.', self::row($crawler, $lena)->filter('.register-row__cost')->text());
    }

    public function test_a_student_who_left_with_a_mark_shows_left_and_only_clear(): void
    {
        $left = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Nina', 'lastName' => 'Albrecht']);
        EnrollmentFactory::new()->left()->create(['course' => $this->course, 'student' => $left]);
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $left]);
        $this->enrolled('Alex', 'Meier');

        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        self::assertResponseIsSuccessful();
        $row = self::row($crawler, $left);
        self::assertStringContainsString('Left the course', $row->filter('.register-row__notes')->text());
        self::assertCount(1, $row->filter('button[name$="[clear]"]'));
        self::assertCount(0, $row->filter('button[name$="[present]"], button[name$="[absent]"]'));
        // The left student's present mark counts; the roster total only counts active enrollments.
        self::assertSelectorTextSame('#lesson-present-count', '1 of 1 present');
    }

    public function test_an_archived_student_shows_archived_and_can_be_marked(): void
    {
        $archived = StudentFactory::new()->archived()->create(['teacher' => $this->teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        EnrollmentFactory::createOne(['course' => $this->course, 'student' => $archived]);

        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        $row = self::row($crawler, $archived);
        self::assertStringContainsString('Archived', $row->filter('.register-row__notes')->text());
        self::assertCount(1, $row->filter('form.register-row__form button[name$="[present]"]'));
        self::assertCount(1, $row->filter('form.register-row__form button[name$="[absent]"]'));
    }

    public function test_a_cancelled_lesson_shows_the_notice_and_offers_only_clear(): void
    {
        $lesson = LessonSessionFactory::new()->cancelled()->create(['course' => $this->course]);
        $marked = $this->enrolled('Alex', 'Meier');
        $unmarked = $this->enrolled('Sam', 'Keller');
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $marked]);

        $crawler = $this->client->request('GET', self::lessonPath($lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.lesson-page .alert--info', 'This lesson is cancelled. Attendance cannot be marked; existing marks can still be cleared.');
        self::assertSelectorTextContains('.lesson-page', 'Cancelled');
        self::assertCount(0, $crawler->filter('button[name$="[present]"], button[name$="[absent]"]'));
        self::assertCount(1, self::row($crawler, $marked)->filter('button[name$="[clear]"]'));
        self::assertCount(1, self::row($crawler, $unmarked)->filter('.register-row__summary--static'));
        self::assertCount(0, self::row($crawler, $unmarked)->filter('form'));
        self::assertSelectorNotExists('form.lesson-page__complete');
        self::assertSelectorNotExists('details.lesson-cancel');
        self::assertSelectorNotExists('.lesson-page__hint');
    }

    public function test_an_empty_register_shows_the_empty_state(): void
    {
        $this->client->request('GET', self::lessonPath($this->lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'No students in this lesson');
        self::assertSelectorNotExists('ul.lesson-register');
        self::assertSelectorNotExists('#lesson-present-count');
    }

    public function test_complete_is_offered_only_once_a_scheduled_lesson_has_started(): void
    {
        self::mockTime('2026-11-10 17:59:00');
        $this->client->request('GET', self::lessonPath($this->lesson));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form.lesson-page__complete');

        self::mockTime('2026-11-10 18:00:00');
        $this->client->request('GET', self::lessonPath($this->lesson));
        self::assertSelectorExists(\sprintf('form.lesson-page__complete[action="%s"]', self::lessonPath($this->lesson, '/complete')));
        self::assertSelectorTextContains('form.lesson-page__complete', 'Complete lesson');

        $completed = LessonSessionFactory::new()->completed()->create(['course' => $this->course, 'startsAtUtc' => new \DateTimeImmutable('2026-11-03 18:00:00'), 'endsAtUtc' => new \DateTimeImmutable('2026-11-03 19:00:00')]);
        $this->client->request('GET', self::lessonPath($completed));
        self::assertSelectorTextContains('.lesson-page', 'Completed');
        self::assertSelectorNotExists('form.lesson-page__complete');
    }

    public function test_cancel_is_offered_without_marks_and_hinted_with_marks(): void
    {
        $alex = $this->enrolled('Alex', 'Meier');

        $this->client->request('GET', self::lessonPath($this->lesson));
        self::assertSelectorExists(\sprintf('details.lesson-cancel > form.lesson-cancel__form[action="%s"]', self::lessonPath($this->lesson, '/cancel')));
        self::assertSelectorTextContains('details.lesson-cancel', 'Cancel this lesson');
        self::assertSelectorNotExists('.lesson-page__hint');

        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $alex, 'status' => AttendanceStatus::Absent]);
        $this->client->request('GET', self::lessonPath($this->lesson));
        self::assertSelectorNotExists('details.lesson-cancel');
        self::assertSelectorTextSame('.lesson-page__hint', 'Clear the marks to cancel this lesson.');
    }

    public function test_an_archived_course_offers_neither_complete_nor_cancel(): void
    {
        $course = CourseFactory::new()->archived()->create(['teacher' => $this->teacher]);
        $lesson = self::lessonAt($course, '2026-11-10 18:00:00');

        $this->client->request('GET', self::lessonPath($lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form.lesson-page__complete');
        self::assertSelectorNotExists('details.lesson-cancel');
        self::assertSelectorNotExists('.lesson-page__hint');
        self::assertSelectorNotExists('.empty-state a');
    }

    public function test_the_page_renders_while_the_checkin_slot_is_absent(): void
    {
        // #16's lesson/_checkin_action.html.twig is included with `ignore missing`.
        if (is_file($this->client->getKernel()->getProjectDir().'/templates/lesson/_checkin_action.html.twig')) {
            self::markTestSkipped('lesson/_checkin_action.html.twig exists; the slot is now covered by #16.');
        }

        $this->client->request('GET', self::lessonPath($this->lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Salsa Beginners');
    }

    private function enrolled(string $firstName, string $lastName, int $credits = 0): Student
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => $firstName, 'lastName' => $lastName]);
        $enrollment = EnrollmentFactory::createOne(['course' => $this->course, 'student' => $student]);
        if ($credits > 0) {
            CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => $credits]);
        }

        return $student;
    }

    private function meta(Crawler $crawler, Student $student): string
    {
        return self::plain(self::row($crawler, $student)->filter('.register-row__meta')->text());
    }

    private function chip(Crawler $crawler, Student $student): string
    {
        return self::row($crawler, $student)->filter('.register-row__balance .balance__chip')->text();
    }
}
