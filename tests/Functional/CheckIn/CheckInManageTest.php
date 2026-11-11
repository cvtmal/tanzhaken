<?php

declare(strict_types=1);

namespace App\Tests\Functional\CheckIn;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\CheckIn\Service\CheckInTokens;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\Attendance\LessonPages;
use App\Tests\Functional\Courses\CoursePages;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The teacher's QR page (#16, QR-1, QR-6, TEC-5): open, rotate, close, the token kept only in the
 * session that opened it, the counts-only status JSON, the states without a button, isolation (S4)
 * and CSRF. Plan Step 4.5, cases 1–10.
 */
#[ResetDatabase]
final class CheckInManageTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;
    use OpensPublicCheckIn;

    /** The public URL in the readonly input: absolute, with a 43-character token (D11). */
    private const PUBLIC_URL = '#^http://localhost/c/([A-Za-z0-9_-]{43})$#D';

    private KernelBrowser $client;
    private Teacher $teacher;
    private Course $course;
    private LessonSession $lesson;

    protected function setUp(): void
    {
        // Tue 10 Nov 2026, 8:30 PM in Zurich; the lesson started at 7:00 PM there.
        self::mockTime('2026-11-10 19:30:00');
        $this->client = self::createClient();
        $this->resetCheckInLimits($this->client);
        $this->teacher = $this->logInTeacher($this->client);
        $this->course = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Salsa Beginners']);
        $this->lesson = self::lessonAt($this->course, '2026-11-10 18:00:00');
    }

    // 1
    public function test_the_page_without_a_window_offers_open_and_writes_nothing(): void
    {
        $crawler = $this->client->request('GET', self::managePath($this->lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.checkin-manage.checkin-manage--offer');
        self::assertSelectorNotExists('[data-checkin-live]');
        self::assertSelectorTextSame('.checkin-manage__title', 'Salsa Beginners');
        self::assertSelectorTextSame('.checkin-manage__text', 'Check-in is closed.');
        self::assertSelectorTextSame('.checkin-manage__caption', 'Shows a QR code for 60 minutes');
        $form = $crawler->filter(\sprintf('form[method="post"][action="%s"]', self::managePath($this->lesson, '/open')));
        self::assertCount(1, $form);
        self::assertSame('Open check-in', trim($form->filter('button[type="submit"]')->text()));
        self::assertNotSame('', (string) $form->filter('input[type="hidden"][name="_token"]')->attr('value'));
        self::assertSelectorNotExists('svg.checkin-qr, .checkin-qr__card');
        self::assertSame(0, self::countRows($this->client, 'check_in_window'), 'The GET never opens a window.');
    }

    // 2
    public function test_opening_through_the_form_shows_the_qr_code_of_the_new_window(): void
    {
        $alex = $this->enrolled('Alex', 'Meier');
        $this->enrolled('Sam', 'Keller');
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $alex]);
        $crawler = $this->client->request('GET', self::managePath($this->lesson));

        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/open')));

        self::assertResponseRedirects(self::managePath($this->lesson), 303);
        $windows = $this->windows($this->lesson);
        self::assertCount(1, $windows);
        self::assertNull($windows[0]['closed_at']);
        self::assertSame('completed', $this->lessonStatus($this->lesson), 'Opening the check-in completes the lesson (S8).');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextContains('.flash-region .toast', 'Check-in is open for 60 minutes.');

        $root = $crawler->filter('.checkin-manage.checkin-manage--open[data-checkin-live]');
        self::assertCount(1, $root);
        self::assertSame(self::managePath($this->lesson, '/status'), $root->attr('data-checkin-status-url'));
        self::assertSame('2026-11-10T20:30:00+00:00', $root->attr('data-checkin-expires-at'));
        self::assertStringContainsString('<svg', (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('.checkin-qr__card[role="img"][aria-label="QR code for the check-in link"] > svg'));

        $token = $this->publicToken($crawler);
        self::assertSame((new CheckInTokens())->hash($token), $windows[0]['token_hash']);
        self::assertCount(1, $crawler->filter('#checkin-public-url[readonly]'));

        self::assertSelectorTextSame('[data-checkin-count]', '1 of 2 checked in');
        self::assertSame('Closes at 9:30 PM', self::plain($crawler->filter('[data-checkin-countdown]')->text()));
        self::assertCount(2, $crawler->filter('[data-checkin-dots] .checkin-dots__dot'));
        self::assertCount(1, $crawler->filter('[data-checkin-dots] .checkin-dots__dot--on'));
        self::assertCount(1, $crawler->filter(\sprintf('form[action="%s"]', self::managePath($this->lesson, '/close'))));
        self::assertCount(0, $crawler->filter(\sprintf('form[action="%s"]', self::managePath($this->lesson, '/open'))));
    }

    // 3
    public function test_reopening_rotates_the_code_and_the_old_url_stops_working(): void
    {
        $this->enrolled('Alex', 'Meier');
        $crawler = $this->client->request('GET', self::managePath($this->lesson));
        $openToken = self::csrfToken($crawler, self::managePath($this->lesson, '/open'));
        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/open')));
        $first = $this->publicToken($this->client->followRedirect());

        $this->client->request('POST', self::managePath($this->lesson, '/open'), ['_token' => $openToken]);

        self::assertResponseRedirects(self::managePath($this->lesson), 303);
        $second = $this->publicToken($this->client->followRedirect());
        self::assertNotSame($first, $second);
        $windows = $this->windows($this->lesson);
        self::assertCount(2, $windows);
        self::assertCount(1, array_filter($windows, static fn (array $window): bool => null === $window['closed_at']), 'One active window after the rotation.');

        $this->client->request('GET', '/c/'.$first);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', "This check-in isn't open");

        $this->client->request('GET', '/c/'.$second);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Salsa Beginners');
        self::assertSelectorExists('form.checkin-roster');
    }

    // 4
    public function test_closing_ends_the_window_forgets_the_code_and_returns_to_the_lesson(): void
    {
        $crawler = $this->client->request('GET', self::managePath($this->lesson));
        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/open')));
        $crawler = $this->client->followRedirect();
        $token = $this->publicToken($crawler);

        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/close')));

        self::assertResponseRedirects(self::lessonPath($this->lesson), 303);
        $windows = $this->windows($this->lesson);
        self::assertCount(1, $windows);
        self::assertNotNull($windows[0]['closed_at']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region .toast', 'Check-in closed.');

        $this->client->request('GET', '/c/'.$token);
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', "This check-in isn't open");

        $this->client->request('GET', self::managePath($this->lesson));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.checkin-manage.checkin-manage--offer');
        self::assertSelectorNotExists('#checkin-public-url');
    }

    // 5
    public function test_a_session_without_the_code_is_offered_reopen_and_close_but_no_qr_code(): void
    {
        $crawler = $this->client->request('GET', self::managePath($this->lesson));
        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/open')));
        $token = $this->publicToken($this->client->followRedirect());

        // The same teacher on another device: a new session.
        $this->client->restart();
        $this->logInTeacher($this->client, $this->teacher);
        $crawler = $this->client->request('GET', self::managePath($this->lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.checkin-manage.checkin-manage--token-missing');
        self::assertSelectorNotExists('[data-checkin-live]');
        self::assertSelectorTextContains('.checkin-manage .alert', 'This code is not shown on this device');
        self::assertSelectorTextContains('.checkin-manage .alert', 'Reopen it to show a new code here; the old code stops working.');
        self::assertSame('Reopen with a new code', trim(self::formFor($crawler, self::managePath($this->lesson, '/open'))->getFormNode()->textContent));
        self::assertSame('Close check-in', trim(self::formFor($crawler, self::managePath($this->lesson, '/close'))->getFormNode()->textContent));
        // No QR code: the only <svg> elements left are the wordmark's tick and the sprite icons
        // (<svg><use href="…">) of the shell and the buttons.
        self::assertSelectorNotExists('.checkin-qr, .checkin-qr__card');
        $others = array_filter($crawler->filter('svg')->each(
            static fn (Crawler $svg): bool => 0 === $svg->filter('use')->count() && null === $svg->closest('.wordmark'),
        ));
        self::assertSame([], $others, 'Every <svg> on the page is the wordmark or a sprite icon.');
        self::assertStringNotContainsString($token, (string) $this->client->getResponse()->getContent());
        self::assertSelectorNotExists('#checkin-public-url');
        self::assertSelectorNotExists('[data-checkin-count]');

        // Reopening here shows a new code on this device.
        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/open')));
        self::assertResponseRedirects(self::managePath($this->lesson), 303);
        self::assertNotSame($token, $this->publicToken($this->client->followRedirect()));
    }

    // 6
    public function test_the_status_json_carries_only_the_counts_and_the_expiry(): void
    {
        $present = $this->enrolled('Alex', 'Meier');
        $absent = $this->enrolled('Sam', 'Keller');
        $this->enrolled('Lena', 'Zimmer');
        $archived = StudentFactory::new()->archived()->create(['teacher' => $this->teacher, 'firstName' => 'Ida', 'lastName' => 'Brunner']);
        EnrollmentFactory::createOne(['course' => $this->course, 'student' => $archived]);
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $present]);
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $absent, 'status' => AttendanceStatus::Absent]);
        AttendanceFactory::createOne(['lessonSession' => $this->lesson, 'student' => $archived]);

        $crawler = $this->client->request('GET', self::managePath($this->lesson));
        $openToken = self::csrfToken($crawler, self::managePath($this->lesson, '/open'));
        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/open')));
        $crawler = $this->client->followRedirect();
        $token = $this->publicToken($crawler);

        $status = $this->statusJson();
        self::assertSame(['state', 'expiresAt', 'secondsLeft', 'checkedIn', 'enrolled'], array_keys($status));
        self::assertSame(['state' => 'open', 'expiresAt' => '2026-11-10T20:30:00+00:00', 'secondsLeft' => 3600, 'checkedIn' => 1, 'enrolled' => 3], $status);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $body = (string) $this->client->getResponse()->getContent();
        foreach (['Alex', 'Meier', 'Sam', 'Keller', 'Lena', 'Zimmer', 'Ida', 'Brunner'] as $name) {
            self::assertStringNotContainsString($name, $body);
        }
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $body);
        self::assertStringNotContainsString($token, $body);

        $this->client->submit(self::formFor($crawler, self::managePath($this->lesson, '/close')));
        self::assertSame(['state' => 'closed', 'expiresAt' => null, 'secondsLeft' => null, 'checkedIn' => 1, 'enrolled' => 3], $this->statusJson());

        // Reopened, then 61 minutes later the window has expired.
        $this->client->request('POST', self::managePath($this->lesson, '/open'), ['_token' => $openToken]);
        self::assertResponseRedirects(self::managePath($this->lesson), 303);
        self::assertSame('open', $this->statusJson()['state']);
        self::mockTime('2026-11-10 20:31:00');
        self::assertSame(['state' => 'closed', 'expiresAt' => null, 'secondsLeft' => null, 'checkedIn' => 1, 'enrolled' => 3], $this->statusJson());
    }

    // 7
    public function test_a_cancelled_lesson_has_no_check_in(): void
    {
        $cancelled = LessonSessionFactory::new()->cancelled()->create(['course' => $this->course]);
        $openToken = self::csrfToken($this->client->request('GET', self::managePath($this->lesson)), self::managePath($this->lesson, '/open'));

        $this->client->request('GET', self::managePath($cancelled));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.checkin-manage.checkin-manage--cancelled');
        self::assertSelectorTextSame('.checkin-manage__text', 'This lesson is cancelled, so it has no check-in.');
        self::assertSelectorNotExists('form');
        self::assertSelectorExists(\sprintf('a[href="%s"]', self::lessonPath($cancelled)));

        $this->client->request('POST', self::managePath($cancelled, '/open'), ['_token' => $openToken]);

        self::assertResponseRedirects(self::managePath($cancelled), 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-region .alert--danger', 'Check-in cannot be opened for this lesson.');
        self::assertSelectorExists('.checkin-manage.checkin-manage--cancelled');
        self::assertSame(0, self::countRows($this->client, 'check_in_window'));
    }

    // 7 (archived course, state c of D2)
    public function test_a_lesson_of_an_archived_course_has_no_check_in(): void
    {
        $archived = CourseFactory::new()->archived()->create(['teacher' => $this->teacher]);
        $lesson = self::lessonAt($archived, '2026-11-10 18:00:00');

        $this->client->request('GET', self::managePath($lesson));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.checkin-manage.checkin-manage--archived');
        self::assertSelectorTextSame('.checkin-manage__text', 'This course is archived, so its lessons have no check-in.');
        self::assertSelectorNotExists('form');
    }

    // 8
    public function test_a_lesson_more_than_15_minutes_ahead_is_not_offered_yet(): void
    {
        $later = self::lessonAt($this->course, '2026-11-10 21:30:00');

        $this->client->request('GET', self::managePath($later));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.checkin-manage.checkin-manage--not-yet');
        self::assertSelectorTextSame('.checkin-manage__text', 'Check-in can be opened from 15 minutes before the lesson starts.');
        self::assertSelectorNotExists('form');
        self::assertSelectorExists(\sprintf('a[href="%s"]', self::lessonPath($later)));
        self::assertSame(0, self::countRows($this->client, 'check_in_window'));
    }

    // 9
    public function test_another_teacher_gets_404_and_changes_nothing(): void
    {
        CheckInWindowFactory::createOne(['lessonSession' => $this->lesson]);
        $other = TeacherFactory::createOne();
        $ownLesson = self::lessonAt(CourseFactory::createOne(['teacher' => $other]), '2026-11-10 18:00:00');
        $this->client->restart();
        $this->logInTeacher($this->client, $other);
        // Valid session tokens of teacher B, taken from B's own lesson.
        $crawler = $this->client->request('GET', self::managePath($ownLesson));
        $openToken = self::csrfToken($crawler, self::managePath($ownLesson, '/open'));
        $this->client->submit(self::formFor($crawler, self::managePath($ownLesson, '/open')));
        $closeToken = self::csrfToken($this->client->followRedirect(), self::managePath($ownLesson, '/close'));

        $this->client->request('GET', self::managePath($this->lesson));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::managePath($this->lesson, '/status'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::managePath($this->lesson, '/open'), ['_token' => $openToken]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::managePath($this->lesson, '/close'), ['_token' => $closeToken]);
        self::assertResponseStatusCodeSame(404);

        $windows = $this->windows($this->lesson);
        self::assertCount(1, $windows, 'No window opened on the foreign lesson.');
        self::assertNull($windows[0]['closed_at'], 'The foreign window is not closed.');
        self::assertSame('scheduled', $this->lessonStatus($this->lesson));
    }

    // 9
    public function test_a_malformed_id_answers_404_and_a_get_on_the_writes_answers_405(): void
    {
        foreach (['/lessons/not-a-uuid/check-in', '/lessons/not-a-uuid/check-in/status'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
        $this->client->request('POST', '/lessons/not-a-uuid/check-in/open');
        self::assertResponseStatusCodeSame(404);

        foreach (['/open', '/close'] as $suffix) {
            $this->client->request('GET', self::managePath($this->lesson, $suffix));
            self::assertResponseStatusCodeSame(405, $suffix);
        }
        self::assertSame(0, self::countRows($this->client, 'check_in_window'));
    }

    // 9
    public function test_an_anonymous_visitor_is_sent_to_the_login(): void
    {
        CheckInWindowFactory::createOne(['lessonSession' => $this->lesson]);
        $this->client->restart();

        foreach ([['GET', ''], ['GET', '/status'], ['POST', '/open'], ['POST', '/close']] as [$method, $suffix]) {
            $this->client->request($method, self::managePath($this->lesson, $suffix));
            self::assertResponseRedirects('/login', null, $method.' '.$suffix);
        }
        $windows = $this->windows($this->lesson);
        self::assertCount(1, $windows);
        self::assertNull($windows[0]['closed_at']);
    }

    // 10
    public function test_an_invalid_csrf_token_opens_nothing(): void
    {
        $this->client->request('POST', self::managePath($this->lesson, '/open'), ['_token' => 'invalid']);

        // #[IsCsrfTokenValid] throws InvalidCsrfTokenException, an AuthenticationException, which the
        // firewall answers with its entry point (as StudentWritesTest records): 302 to /login, not 303 to the page.
        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login');
        self::assertSame(0, self::countRows($this->client, 'check_in_window'));
        self::assertSame('scheduled', $this->lessonStatus($this->lesson));
    }

    // 10
    public function test_an_invalid_csrf_token_closes_nothing(): void
    {
        CheckInWindowFactory::createOne(['lessonSession' => $this->lesson]);

        $this->client->request('POST', self::managePath($this->lesson, '/close'), ['_token' => 'invalid']);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/login');
        $windows = $this->windows($this->lesson);
        self::assertCount(1, $windows);
        self::assertNull($windows[0]['closed_at']);
    }

    private static function managePath(LessonSession $lesson, string $suffix = ''): string
    {
        return '/lessons/'.$lesson->getId()->toRfc4122().'/check-in'.$suffix;
    }

    /**
     * The hidden `_token` of the one form posting to `$action`.
     */
    private static function csrfToken(Crawler $crawler, string $action): string
    {
        self::formFor($crawler, $action);
        $token = (string) $crawler->filter(\sprintf('form[action="%s"] input[type="hidden"][name="_token"]', $action))->attr('value');
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * The plain token in the readonly URL field of the open page.
     */
    private function publicToken(Crawler $crawler): string
    {
        self::assertResponseIsSuccessful();
        $input = $crawler->filter('#checkin-public-url');
        self::assertCount(1, $input, 'The open page shows the check-in link.');
        if (1 !== preg_match(self::PUBLIC_URL, (string) $input->attr('value'), $match)) {
            self::fail(\sprintf('An absolute /c/<token> URL, got "%s".', (string) $input->attr('value')));
        }

        return $match[1];
    }

    /**
     * @return list<array{token_hash: string, closed_at: ?string}>
     */
    private function windows(LessonSession $lesson): array
    {
        /** @var list<array{token_hash: string, closed_at: ?string}> $rows */
        $rows = self::db($this->client)->fetchAllAssociative(
            'SELECT token_hash, closed_at FROM check_in_window WHERE lesson_session_id = ? ORDER BY opened_at, rowid',
            [$lesson->getId()->toBinary()],
        );

        return $rows;
    }

    private function lessonStatus(LessonSession $lesson): string
    {
        return (string) self::db($this->client)->fetchOne('SELECT status FROM lesson_session WHERE id = ?', [$lesson->getId()->toBinary()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusJson(): array
    {
        $this->client->request('GET', self::managePath($this->lesson, '/status'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $status = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($status);

        /* @var array<string, mixed> $status */
        return $status;
    }

    private function enrolled(string $firstName, string $lastName): Student
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => $firstName, 'lastName' => $lastName]);
        EnrollmentFactory::createOne(['course' => $this->course, 'student' => $student]);

        return $student;
    }
}
