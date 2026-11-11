<?php

declare(strict_types=1);

namespace App\Tests\Functional\CheckIn;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\LessonSessionFactory;
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
 * #16's slot in #13's lesson page actions (D6, P1–P3): Open check-in from 15 minutes before the start,
 * the open window's badge with Show QR code and Close, nothing for a cancelled lesson. Plan Step 4.6.
 */
#[ResetDatabase]
final class CheckInLessonSlotTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;

    private const SLOT = '.page-header__actions > .checkin-slot';

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
        $this->course = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Salsa Beginners']);
        $this->lesson = self::lessonAt($this->course, '2026-11-10 18:00:00');
    }

    public function test_a_started_lesson_without_a_window_offers_open_check_in(): void
    {
        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        self::assertResponseIsSuccessful();
        $slot = self::slot($crawler);
        $form = $slot->filter(\sprintf('form[method="post"][action="%s"]', self::checkInPath($this->lesson, '/open')));
        self::assertCount(1, $form);
        self::assertSame('Open check-in', trim($form->filter('button[type="submit"]')->text()));
        self::assertSame('Shows a QR code for 60 minutes', $slot->filter('.checkin-slot__caption')->text());
        self::assertCount(0, $slot->filter(\sprintf('a[href="%s"]', self::checkInPath($this->lesson))));
        self::assertCount(0, $slot->filter(\sprintf('form[action="%s"]', self::checkInPath($this->lesson, '/close'))));

        $this->client->submit($form->form());

        self::assertResponseRedirects(self::checkInPath($this->lesson), 303);
        self::assertSame(1, self::countRows($this->client, 'check_in_window'));
    }

    public function test_an_open_window_shows_its_end_the_qr_link_and_close(): void
    {
        CheckInWindowFactory::createOne(['lessonSession' => $this->lesson]);

        $crawler = $this->client->request('GET', self::lessonPath($this->lesson));

        self::assertResponseIsSuccessful();
        $slot = self::slot($crawler);
        // The window expires at 20:30 UTC: 9:30 PM in the course's zone (Europe/Zurich).
        self::assertStringContainsString('Open until 9:30 PM', self::plain($slot->text()));
        $show = $slot->filter(\sprintf('a[href="%s"]', self::checkInPath($this->lesson)));
        self::assertCount(1, $show);
        self::assertSame('Show QR code', trim($show->text()));
        $close = $slot->filter(\sprintf('form[method="post"][action="%s"]', self::checkInPath($this->lesson, '/close')));
        self::assertCount(1, $close);
        self::assertSame('Close check-in', trim($close->filter('button[type="submit"]')->text()));
        self::assertCount(0, $slot->filter(\sprintf('form[action="%s"]', self::checkInPath($this->lesson, '/open'))));
    }

    public function test_a_cancelled_lesson_has_no_slot(): void
    {
        $cancelled = LessonSessionFactory::new()->cancelled()->create(['course' => $this->course, 'startsAtUtc' => new \DateTimeImmutable('2026-11-10 17:00:00', new \DateTimeZone('UTC')), 'endsAtUtc' => new \DateTimeImmutable('2026-11-10 18:00:00', new \DateTimeZone('UTC'))]);

        $crawler = $this->client->request('GET', self::lessonPath($cancelled));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.checkin-slot'));
        self::assertCount(0, $crawler->filter('form[action*="/check-in/"]'));
        self::assertCount(0, $crawler->filter('a[href$="/check-in"]'));
    }

    public function test_a_lesson_in_two_hours_without_a_window_offers_nothing_yet(): void
    {
        $later = self::lessonAt($this->course, '2026-11-10 21:30:00');

        $crawler = $this->client->request('GET', self::lessonPath($later));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.checkin-slot'));
        self::assertCount(0, $crawler->filter(\sprintf('form[action="%s"]', self::checkInPath($later, '/open'))));
    }

    public function test_the_badge_reads_the_viewing_teachers_own_window(): void
    {
        // Teacher A's lesson has an open window; teacher B's lesson at the same time has none.
        CheckInWindowFactory::createOne(['lessonSession' => $this->lesson]);
        $other = TeacherFactory::createOne();
        $ownLesson = self::lessonAt(CourseFactory::createOne(['teacher' => $other]), '2026-11-10 18:00:00');
        $this->client->restart();
        $this->logInTeacher($this->client, $other);

        $crawler = $this->client->request('GET', self::lessonPath($ownLesson));

        self::assertResponseIsSuccessful();
        $slot = self::slot($crawler);
        self::assertStringNotContainsString('Open until', $slot->text());
        self::assertCount(1, $slot->filter(\sprintf('form[action="%s"]', self::checkInPath($ownLesson, '/open'))));
        self::assertCount(0, $crawler->filter('a[href$="/check-in"]'));
    }

    private static function slot(Crawler $crawler): Crawler
    {
        $slot = $crawler->filter(self::SLOT);
        self::assertCount(1, $slot, 'One check-in slot in the page actions.');

        return $slot;
    }

    private static function checkInPath(LessonSession $lesson, string $suffix = ''): string
    {
        return '/lessons/'.$lesson->getId()->toRfc4122().'/check-in'.$suffix;
    }
}
