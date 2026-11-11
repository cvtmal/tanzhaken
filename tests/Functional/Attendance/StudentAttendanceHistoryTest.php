<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\Courses\CoursePages;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The attendance history on the student page (STU-4, R11, #13): every mark of the student across
 * the teacher's courses, newest lesson first, in each course's zone, linking to the lesson page.
 */
#[ResetDatabase]
final class StudentAttendanceHistoryTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;

    private const ROWS = '#student-attendance-heading ~ .list .list-row';

    private KernelBrowser $client;
    private Teacher $teacher;

    protected function setUp(): void
    {
        self::mockTime('2026-11-20 12:00:00');
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
    }

    public function test_the_history_lists_the_marks_newest_first_with_course_status_and_link(): void
    {
        $alex = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        $salsa = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Salsa Beginners']);
        $tango = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Tango Night', 'timezone' => 'Europe/London']);
        EnrollmentFactory::createOne(['course' => $salsa, 'student' => $alex]);
        EnrollmentFactory::createOne(['course' => $tango, 'student' => $alex]);
        $tangoLesson = self::lessonAt($tango, '2026-11-03 19:00:00');
        $salsaLesson = self::lessonAt($salsa, '2026-11-10 18:00:00');
        $cancelled = LessonSessionFactory::new()->cancelled()->create([
            'course' => $salsa,
            'startsAtUtc' => new \DateTimeImmutable('2026-11-17 18:00:00'),
            'endsAtUtc' => new \DateTimeImmutable('2026-11-17 19:00:00'),
        ]);
        AttendanceFactory::createOne(['lessonSession' => $tangoLesson, 'student' => $alex, 'source' => AttendanceSource::StudentQr]);
        AttendanceFactory::createOne(['lessonSession' => $salsaLesson, 'student' => $alex]);
        AttendanceFactory::createOne(['lessonSession' => $cancelled, 'student' => $alex, 'status' => AttendanceStatus::Absent]);

        $crawler = $this->client->request('GET', '/students/'.$alex->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#student-attendance-heading', 'Attendance');
        self::assertSelectorNotExists('.student-history__empty');
        $rows = $crawler->filter(self::ROWS)->each(static fn (Crawler $row): array => [
            $row->filter('.list-row__title')->text(),
            self::plain($row->filter('.list-row__meta')->text()),
            $row->filter('.list-row__trailing')->text(),
            (string) $row->filter('a.list-row__link')->attr('href'),
        ]);
        self::assertSame([
            ['Salsa Beginners', 'Tue 17 Nov 2026, 7:00 PM · Marked by you · Lesson cancelled', 'Absent', self::lessonPath($cancelled)],
            ['Salsa Beginners', 'Tue 10 Nov 2026, 7:00 PM · Marked by you', 'Present', self::lessonPath($salsaLesson)],
            // In the course's zone (London), not the teacher's (Zurich, 8:00 PM).
            ['Tango Night', 'Tue 3 Nov 2026, 7:00 PM · Checked in by QR', 'Present', self::lessonPath($tangoLesson)],
        ], $rows);

        $this->client->click($crawler->filter(self::ROWS)->eq(1)->filter('a.list-row__link')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Salsa Beginners');
    }

    public function test_another_teachers_marks_never_appear(): void
    {
        $alex = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        AttendanceFactory::createOne([
            'lessonSession' => self::lessonAt(CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Salsa Beginners']), '2026-11-10 18:00:00'),
            'student' => $alex,
        ]);
        $other = TeacherFactory::createOne();
        $foreignAlex = StudentFactory::createOne(['teacher' => $other, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        AttendanceFactory::createOne([
            'lessonSession' => self::lessonAt(CourseFactory::createOne(['teacher' => $other, 'name' => 'Foreign Bachata']), '2026-11-11 18:00:00'),
            'student' => $foreignAlex,
        ]);

        $crawler = $this->client->request('GET', '/students/'.$alex->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::ROWS));
        self::assertStringNotContainsString('Foreign Bachata', $crawler->filter('main')->text());

        $this->client->request('GET', '/students/'.$foreignAlex->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function test_a_student_without_marks_shows_the_empty_text(): void
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher]);

        $this->client->request('GET', '/students/'.$student->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.student-history__empty', 'No attendance marked yet.');
        self::assertSelectorNotExists(self::ROWS);
    }
}
