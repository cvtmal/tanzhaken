<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Dashboard\ReadModel\TodaysLessons;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The "Today" page (#17) renders the board, the hero check-in link and the passes to renew for the
 * logged-in teacher only, and its query count does not grow with the data (R1).
 */
#[ResetDatabase]
final class DashboardPageTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogsInTeacher;

    /** UTC: 20:02 on Tuesday 10 November in Zurich (CET, UTC+1), as in B1. */
    private const NOW = '2026-11-10 19:02:00';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::mockTime(self::NOW);
    }

    public function test_anonymous_visitor_is_sent_to_login(): void
    {
        $this->client->request('GET', '/dashboard');

        self::assertResponseRedirects('/login');
    }

    public function test_teacher_without_course_sees_the_empty_state(): void
    {
        $this->logInTeacher($this->client, self::teacher());
        CourseFactory::createOne(); // another teacher's

        $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'No courses yet');
        self::assertSelectorExists('.empty-state a[href="/courses/new"]');
        self::assertSelectorNotExists('.card--hero');
        self::assertSelectorNotExists('.dashboard');
    }

    public function test_board_with_hero_later_lesson_upcoming_and_renewals(): void
    {
        $teacher = $this->logInTeacher($this->client, self::teacher());
        $salsa = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Salsa Beginners', 'location' => 'Studio 2']);
        $bachata = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Bachata Intermediate']);
        $kizomba = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Kizomba Basics']);
        $ended = self::lessonAt($salsa, '2026-11-10 18:00:00'); // 19:00–20:00 local, ended 2 min ago
        $later = self::lessonAt($bachata, '2026-11-10 19:42:00'); // 20:42 local, in 40 min
        $upcoming = self::lessonAt($kizomba, '2026-11-12 17:30:00'); // Thursday 12, 18:30 local

        $david = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'David', 'lastName' => 'Roth']);
        EnrollmentFactory::createOne(['course' => $salsa, 'student' => $david]);
        AttendanceFactory::createOne(['lessonSession' => $ended, 'student' => $david]); // no grant: balance −1
        $noah = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Noah', 'lastName' => 'Frei']);
        CreditGrantFactory::createOne([
            'enrollment' => EnrollmentFactory::createOne(['course' => $salsa, 'student' => $noah]),
            'quantity' => 5,
        ]);

        $foreign = CourseFactory::createOne(['name' => 'Foreign Tango']);
        self::lessonAt($foreign, '2026-11-10 18:30:00');
        EnrollmentFactory::createOne([
            'course' => $foreign,
            'student' => StudentFactory::new()->create(['teacher' => $foreign->getTeacher(), 'firstName' => 'Fiona', 'lastName' => 'Fremd']),
        ]);

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-header__title', 'Good evening, Lena Brunner');
        self::assertSelectorTextContains('.dashboard__date', 'Tuesday, November 10, 2026');

        // Hero: the lesson that just ended carries the page's only filled button.
        self::assertSelectorTextContains('.card--hero', 'Ended 2 min ago');
        self::assertSelectorTextContains('.card--hero', 'Salsa Beginners');
        self::assertSelectorTextContains('.card--hero .card__meta', 'Studio 2');
        self::assertCount(1, $crawler->filter('.btn--primary'));
        $primary = $crawler->filter('.btn--primary');
        self::assertSame('/lessons/'.$ended->getId()->toRfc4122().'/check-in', $primary->attr('href'));
        self::assertStringContainsString('Open check-in', $primary->text());
        $lessonPath = '/courses/'.$salsa->getId()->toRfc4122().'/lessons/'.$ended->getId()->toRfc4122();
        self::assertSame('View lesson', $crawler->filter('.card--hero a[href="'.$lessonPath.'"]')->text());

        // The later lesson: not yet within the 15-minute lead, so "Open lesson".
        $compact = $crawler->filter('.card--compact');
        self::assertCount(1, $compact);
        self::assertStringContainsString('Bachata Intermediate', $compact->text());
        self::assertStringContainsString('in 40 min', $compact->text());
        $open = $compact->filter('a.btn');
        self::assertSame('Open lesson', $open->text());
        self::assertSame('/courses/'.$bachata->getId()->toRfc4122().'/lessons/'.$later->getId()->toRfc4122(), $open->attr('href'));

        // Passes to renew: balance ≤ 0 only, own students only.
        $renewals = $crawler->filter('.dashboard__renewals');
        self::assertStringContainsString('1 student', $renewals->filter('.dashboard__count')->text());
        $rows = $renewals->filter('.list-row');
        self::assertCount(1, $rows);
        self::assertStringContainsString('David Roth', $rows->text());
        self::assertStringContainsString('In today’s Salsa Beginners', $rows->text());
        self::assertSame('−1', $rows->filter('.balance__chip')->text());
        self::assertSame('/students/'.$david->getId()->toRfc4122(), $rows->filter('a.list-row__link')->attr('href'));
        self::assertStringNotContainsString('Noah Frei', $crawler->filter('main')->text());

        // Upcoming: date tile and a link to the lesson.
        $row = $crawler->filter('.dashboard__upcoming .list-row');
        self::assertCount(1, $row);
        self::assertSame('THU', $row->filter('.list-row__weekday')->text());
        self::assertSame('12', $row->filter('.list-row__day')->text());
        self::assertStringContainsString('Kizomba Basics', $row->text());
        self::assertMatchesRegularExpression('/6:30\h*PM · No students/u', $row->filter('.list-row__meta')->text());
        self::assertSame(
            '/courses/'.$kizomba->getId()->toRfc4122().'/lessons/'.$upcoming->getId()->toRfc4122(),
            $row->filter('a.list-row__link')->attr('href'),
        );

        // Tenancy: another teacher's course and student never appear.
        self::assertStringNotContainsString('Foreign Tango', $crawler->filter('main')->text());
        self::assertStringNotContainsString('Fiona', $crawler->filter('main')->text());
    }

    public function test_open_window_shows_check_in_open_and_show_check_in(): void
    {
        $teacher = $this->logInTeacher($this->client, self::teacher());
        $course = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Lindy Hop']);
        $lesson = self::lessonAt($course, '2026-11-10 18:45:00'); // in progress
        CheckInWindowFactory::createOne(['lessonSession' => $lesson]);

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card--hero', 'Check-in open');
        $primary = $crawler->filter('.btn--primary');
        self::assertCount(1, $primary);
        self::assertStringContainsString('Show check-in', $primary->text());
        self::assertSame('/lessons/'.$lesson->getId()->toRfc4122().'/check-in', $primary->attr('href'));
        self::assertSelectorTextContains('.dashboard__renewals', 'No passes to renew');
        self::assertSelectorTextContains('.dashboard__upcoming', 'Nothing scheduled in the next 7 days');
    }

    public function test_page_query_count_does_not_grow_with_the_data(): void
    {
        $this->client->disableReboot();
        $small = self::teacher();
        self::seedBoard($small, 2);
        $large = TeacherFactory::createOne(['name' => 'Mara Keller']);
        self::seedBoard($large, 20);

        $smallCount = $this->pageQueryCount($small, 2);
        $largeCount = $this->pageQueryCount($large, 20);

        self::assertSame($smallCount, $largeCount, \sprintf('%d queries with 2 items, %d with 20', $smallCount, $largeCount));
    }

    private function pageQueryCount(Teacher $teacher, int $size): int
    {
        $this->logInTeacher($this->client, $teacher);
        $container = $this->client->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear(); // as in a fresh request: the user is reloaded from the database
        $holder = $container->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();

        $crawler = $this->client->request('GET', '/dashboard');

        self::assertResponseIsSuccessful();
        self::assertCount($size, $crawler->filter('.dashboard__renewals .list-row'));
        self::assertCount(min($size, TodaysLessons::UPCOMING_LIMIT), $crawler->filter('.dashboard__upcoming .list-row'));
        self::assertCount($size, $crawler->filter('.dashboard__today article'));

        return \count($holder->getData()['default'] ?? []);
    }

    /**
     * Per item: a course with a lesson that ended today (open window, one present attendance)
     * and one tomorrow, and an enrolled student without a grant (renewal row).
     */
    private static function seedBoard(Teacher $teacher, int $size): void
    {
        for ($i = 0; $i < $size; ++$i) {
            $course = CourseFactory::createOne(['teacher' => $teacher]);
            $today = self::lessonAt($course, \sprintf('2026-11-10 %02d:%02d:00', 8 + intdiv($i, 4), ($i % 4) * 15));
            self::lessonAt($course, '2026-11-11 18:00:00');
            CheckInWindowFactory::createOne(['lessonSession' => $today]);
            $student = StudentFactory::createOne(['teacher' => $teacher]);
            EnrollmentFactory::createOne(['course' => $course, 'student' => $student]);
            AttendanceFactory::createOne(['lessonSession' => $today, 'student' => $student]);
        }
    }

    private static function teacher(): Teacher
    {
        return TeacherFactory::createOne(['name' => 'Lena Brunner', 'timezone' => 'Europe/Zurich']);
    }

    private static function lessonAt(Course $course, string $startsAtUtc): LessonSession
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc);

        return LessonSessionFactory::createOne([
            'course' => $course,
            'startsAtUtc' => $startsAt,
            'endsAtUtc' => $startsAt->modify(\sprintf('+%d minutes', $course->getDurationMinutes())),
        ]);
    }
}
