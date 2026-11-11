<?php

declare(strict_types=1);

namespace App\Tests\Functional\Courses;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Form\ConfirmActionType;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The roster of the course page (STU-3, CRS-6): enrol, picker, check-in label, remove, re-enrol.
 */
#[ResetDatabase]
final class CourseRosterTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LogsInTeacher;

    private KernelBrowser $client;
    private Teacher $teacher;
    private Course $course;
    private string $path;

    protected function setUp(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
        $this->course = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Monday Salsa', 'weekday' => 1]);
        $this->path = self::coursePath($this->course->getId());
    }

    public function test_enrol_two_students_puts_both_on_the_roster(): void
    {
        $alex = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        $sam = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Sam', 'lastName' => 'Keller']);

        $this->enrol($alex->getId(), 'Alex M');
        $crawler = $this->enrol($sam->getId());

        self::assertSame(['Sam Keller', 'Alex Meier'], $this->rosterNames($crawler));
        self::assertSelectorTextContains('tr.roster-row:last-child', 'Alex M');
    }

    public function test_picker_offers_neither_archived_nor_enrolled_students(): void
    {
        $free = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Free', 'lastName' => 'Student']);
        StudentFactory::new(['teacher' => $this->teacher, 'firstName' => 'Archived', 'lastName' => 'Student'])->archived()->create();
        $enrolled = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Enrolled', 'lastName' => 'Student']);
        EnrollmentFactory::createOne(['course' => $this->course, 'student' => $enrolled]);
        StudentFactory::createOne(['teacher' => TeacherFactory::createOne(), 'firstName' => 'Foreign', 'lastName' => 'Student']);

        $crawler = $this->client->request('GET', $this->path);

        $options = $crawler->filter(\sprintf('select[name="%s[student]"] option', $this->enrollName()))
            ->each(static fn (Crawler $option): string => (string) $option->attr('value'));
        self::assertSame(['', $free->getId()->toRfc4122()], $options);
    }

    public function test_label_is_saved_and_refused_when_another_student_uses_it(): void
    {
        $first = EnrollmentFactory::createOne(['course' => $this->course]);
        $second = EnrollmentFactory::createOne(['course' => $this->course]);

        $crawler = $this->client->request('GET', $this->path);
        $this->client->submit(self::formFor($crawler, $this->enrollmentPath($first->getId(), '/label')), [
            ConfirmActionType::formName('label', $first->getId()).'[label]' => 'Zoë',
        ]);
        $crawler = self::followToFlash($this->client, $this->path, 'success', 'Check-in label of');
        self::assertSelectorTextContains('.roster-row__label', 'Zoë');

        $this->client->submit(self::formFor($crawler, $this->enrollmentPath($second->getId(), '/label')), [
            ConfirmActionType::formName('label', $second->getId()).'[label]' => 'ZOË',
        ]);
        self::followToFlash($this->client, $this->path, 'error', 'Another student of this course already uses the check-in label “ZOË”.');
        self::assertNull(self::db($this->client)->fetchOne(
            'SELECT public_checkin_label FROM enrollment WHERE id = ?',
            [$second->getId()->toBinary()],
        ));
    }

    public function test_remove_takes_the_student_off_the_roster_and_keeps_the_history(): void
    {
        $enrollment = EnrollmentFactory::createOne(['course' => $this->course]);
        CreditGrantFactory::createOne(['enrollment' => $enrollment]);
        $lesson = LessonSessionFactory::createOne(['course' => $this->course]);
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $enrollment->getStudent()]);
        $grants = self::countRows($this->client, 'credit_grant');
        $attendance = self::countRows($this->client, 'attendance');

        $crawler = $this->client->request('GET', $this->path);
        self::assertCount(1, $crawler->filter('tr.roster-row'));
        $this->client->submit(self::formFor($crawler, $this->enrollmentPath($enrollment->getId(), '/remove')));

        $crawler = self::followToFlash($this->client, $this->path, 'success', 'removed from the course.');
        self::assertCount(0, $crawler->filter('tr.roster-row'));
        self::assertSame($grants, self::countRows($this->client, 'credit_grant'));
        self::assertSame($attendance, self::countRows($this->client, 'attendance'));
        self::assertSame(1, self::countWhere($this->client, 'enrollment', 'id', $enrollment->getId()));
    }

    public function test_re_enrolling_a_removed_student_reuses_the_enrollment(): void
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        $enrollment = EnrollmentFactory::new(['course' => $this->course, 'student' => $student])->left()->create();

        $crawler = $this->enrol($student->getId());

        self::assertSame(['Alex Meier'], $this->rosterNames($crawler));
        self::assertSame(1, self::countWhere($this->client, 'enrollment', 'student_id', $student->getId()));
        self::assertCount(1, $crawler->filter(\sprintf('form[action="%s"]', $this->enrollmentPath($enrollment->getId(), '/remove'))));
    }

    private function enrol(Uuid $studentId, string $label = ''): Crawler
    {
        $crawler = $this->client->request('GET', $this->path);
        $this->client->submit(self::formFor($crawler, $this->path.'/enrollments'), [
            $this->enrollName().'[student]' => $studentId->toRfc4122(),
            $this->enrollName().'[label]' => $label,
        ]);

        return self::followToFlash($this->client, $this->path, 'success', 'enrolled.');
    }

    private function enrollName(): string
    {
        return ConfirmActionType::formName('enroll', $this->course->getId());
    }

    private function enrollmentPath(Uuid $enrollmentId, string $action): string
    {
        return $this->path.'/enrollments/'.$enrollmentId->toRfc4122().$action;
    }

    /**
     * @return list<string>
     */
    private function rosterNames(Crawler $crawler): array
    {
        return $crawler->filter('tr.roster-row .roster-row__name')->each(static fn (Crawler $name): string => trim($name->text()));
    }
}
