<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `course_export` (#15, REG-6): download headers, the bytes (BOM, CRLF), both sections, local
 * times across DST, the balance summary, formula neutralisation, 404s and the course-page button.
 */
#[ResetDatabase]
final class CourseExportTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogsInTeacher;

    private const REGISTER_HEADER = ['Date', 'Time', 'Time zone', 'Student', 'Status'];
    private const SUMMARY_HEADER = ['Student', 'Lessons bought', 'Lessons used', 'Lessons left', 'State', 'Enrolment'];

    private KernelBrowser $client;
    private Teacher $teacher;
    private Course $course;

    protected function setUp(): void
    {
        self::mockTime('2026-07-15 12:00:00');
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
        $this->course = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Monday Salsa', 'timezone' => 'Europe/Zurich']);
    }

    public function test_download_headers(): void
    {
        $this->export($this->course);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=register-monday-salsa-2026-07-15.csv');
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function test_bytes_and_register_section_in_the_course_zone(): void
    {
        $winter = self::lesson($this->course, '2026-01-13 18:00');
        $summer = self::lesson($this->course, '2026-07-07 17:00');
        $anna = $this->enrolled('Anna', 'Arnold');
        $ben = $this->enrolled('Ben', 'Brun');
        $this->enrolled('Cleo', 'Conti');
        self::mark($winter, $anna, AttendanceStatus::Present);
        self::mark($summer, $anna, AttendanceStatus::Absent);
        self::mark($summer, $ben, AttendanceStatus::Present);

        $body = $this->export($this->course);

        self::assertSame("\xEF\xBB\xBF", substr($body, 0, 3));
        self::assertSame(1, substr_count($body, "\xEF\xBB\xBF"));
        self::assertStringEndsWith("\r\n", $body);
        self::assertSame(substr_count($body, "\n"), substr_count($body, "\r\n"));

        $rows = self::parse($body);
        self::assertSame(self::REGISTER_HEADER, $rows[0]);
        self::assertSame([
            ['2026-01-13', '19:00', 'Europe/Zurich', 'Anna Arnold', 'Present'],
            ['2026-01-13', '19:00', 'Europe/Zurich', 'Ben Brun', 'Not marked'],
            ['2026-01-13', '19:00', 'Europe/Zurich', 'Cleo Conti', 'Not marked'],
            ['2026-07-07', '19:00', 'Europe/Zurich', 'Anna Arnold', 'Absent'],
            ['2026-07-07', '19:00', 'Europe/Zurich', 'Ben Brun', 'Present'],
            ['2026-07-07', '19:00', 'Europe/Zurich', 'Cleo Conti', 'Not marked'],
        ], \array_slice($rows, 1, 6));
        self::assertSame([], $rows[7]);
        self::assertSame(['Balance summary'], $rows[8]);
        self::assertSame(self::SUMMARY_HEADER, $rows[9]);
    }

    public function test_balance_summary_from_balances_with_a_negative_number(): void
    {
        $anna = $this->enrolled('Anna', 'Arnold');
        $enrollment = $this->enrollmentOf($anna);
        CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => 10]);
        for ($week = 0; $week < 11; ++$week) {
            $lesson = self::lesson($this->course, (new \DateTimeImmutable('2026-03-03 18:00'))->modify(\sprintf('+%d weeks', $week))->format('Y-m-d H:i'));
            self::mark($lesson, $anna, AttendanceStatus::Present);
        }
        $ben = $this->enrolled('Ben', 'Brun', left: true);
        CreditGrantFactory::createOne(['enrollment' => $this->enrollmentOf($ben), 'quantity' => 10]);

        $body = $this->export($this->course);

        self::assertStringContainsString("\"Anna Arnold\",10,11,-1,\"Renewal needed\",Enrolled\r\n", $body);
        $rows = self::parse($body);
        $summary = \array_slice($rows, array_search(['Balance summary'], $rows, true) + 1);
        self::assertSame([
            self::SUMMARY_HEADER,
            ['Anna Arnold', '10', '11', '-1', 'Renewal needed', 'Enrolled'],
            ['Ben Brun', '10', '0', '10', 'OK', 'Left'],
        ], $summary);
    }

    public function test_formula_cells_are_neutralised_in_both_sections(): void
    {
        $lesson = self::lesson($this->course, '2026-07-07 17:00');
        foreach (['=HYPERLINK("http://evil","x")', '@SUM(A1)', '+1', '-1'] as $firstName) {
            self::mark($lesson, $this->enrolled($firstName, 'Meier'), AttendanceStatus::Present);
        }

        $rows = self::parse($this->export($this->course));

        $names = array_values(array_filter(
            array_merge(...$rows),
            static fn (string $cell): bool => str_ends_with($cell, ' Meier'),
        ));
        self::assertCount(8, $names);
        foreach ($names as $name) {
            self::assertStringStartsWith("'", $name);
        }
        self::assertContains("'=HYPERLINK(\"http://evil\",\"x\") Meier", $names);
        self::assertContains("'-1 Meier", $names);
    }

    public function test_a_formula_course_name_only_shapes_the_filename(): void
    {
        $course = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => '=Salsa Night', 'timezone' => 'Europe/Zurich']);

        $body = $this->export($course);

        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=register-salsa-night-2026-07-15.csv');
        self::assertStringNotContainsString('Salsa Night', $body);
    }

    public function test_foreign_unknown_and_malformed_ids_answer_404(): void
    {
        $foreign = CourseFactory::createOne();

        $this->client->request('GET', self::exportPath($foreign->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::exportPath(Uuid::v7()->toRfc4122()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', self::exportPath('x'));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_anonymous_is_sent_to_login(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->restart();

        $this->client->request('GET', self::exportPath($this->course->getId()->toRfc4122()));

        self::assertResponseRedirects('/login');
    }

    public function test_course_page_shows_the_button_on_active_and_archived_courses(): void
    {
        $archived = CourseFactory::new(['teacher' => $this->teacher, 'name' => 'Old Tango'])->archived()->create();

        foreach ([$this->course, $archived] as $course) {
            $this->client->request('GET', '/courses/'.$course->getId()->toRfc4122());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextSame('a[href="'.self::exportPath($course->getId()->toRfc4122()).'"][download]', 'Export CSV');
        }
    }

    private function export(Course $course): string
    {
        $this->client->request('GET', self::exportPath($course->getId()->toRfc4122()));

        return (string) $this->client->getResponse()->getContent();
    }

    private static function exportPath(string $id): string
    {
        return '/courses/'.$id.'/export.csv';
    }

    /**
     * @return list<list<string>> an empty line is []
     */
    private static function parse(string $body): array
    {
        $handle = fopen('php://temp', 'r+');
        self::assertNotFalse($handle);
        fwrite($handle, substr($body, 3));
        rewind($handle);
        $rows = [];
        while (false !== ($row = fgetcsv($handle, null, ',', '"', ''))) {
            $rows[] = [null] === $row ? [] : array_map(static fn (?string $cell): string => (string) $cell, $row);
        }
        fclose($handle);

        return $rows;
    }

    private static function lesson(Course $course, string $startsAtUtc): LessonSession
    {
        $startsAt = new \DateTimeImmutable($startsAtUtc);

        return LessonSessionFactory::createOne(['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')]);
    }

    private function enrolled(string $firstName, string $lastName, bool $left = false): Student
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => $firstName, 'lastName' => $lastName]);
        $factory = EnrollmentFactory::new(['course' => $this->course, 'student' => $student, 'now' => new \DateTimeImmutable('2026-01-01 10:00')]);
        ($left ? $factory->left() : $factory)->create();

        return $student;
    }

    private function enrollmentOf(Student $student): Enrollment
    {
        return EnrollmentFactory::repository()->findOneBy(['course' => $this->course, 'student' => $student])
            ?? throw new \LogicException('No enrollment.');
    }

    private static function mark(LessonSession $lesson, Student $student, AttendanceStatus $status): void
    {
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $student, 'status' => $status]);
    }
}
