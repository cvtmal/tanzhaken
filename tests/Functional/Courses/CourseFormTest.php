<?php

declare(strict_types=1);

namespace App\Tests\Functional\Courses;

use App\Courses\Form\ConfirmActionType;
use App\Tests\Factory\CourseFactory;
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
 * Create, edit and archive a course (CRS-1, CRS-2, D1).
 */
#[ResetDatabase]
final class CourseFormTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LogsInTeacher;

    public function test_create_redirects_to_the_course_page_with_26_lessons(): void
    {
        self::mockTime('2026-03-01 10:00:00');
        $client = self::createClient();
        $this->logInTeacher($client);

        $id = $this->createCourse($client, ['course[name]' => 'Salsa Beginners']);

        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-region .toast', 'Course “Salsa Beginners” created.');
        self::assertSelectorTextContains('h1', 'Salsa Beginners');
        self::assertCount(26, $crawler->filter('.lesson-row'));
        self::assertSame('Mon 2 Mar', trim($crawler->filter('.lesson-row__date')->first()->text()));
        self::assertSame(26, self::countWhere($client, 'lesson_session', 'course_id', $id));
    }

    public function test_empty_name_re_renders_the_form_with_422(): void
    {
        self::mockTime('2026-03-01 10:00:00');
        $client = self::createClient();
        $this->logInTeacher($client);

        $crawler = $client->request('GET', '/courses/new');
        $client->submit($crawler->selectButton('Create course')->form(), $this->courseFields(['course[name]' => '']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a course name.', (string) $client->getResponse()->getContent());
        self::assertSame(0, self::countRows($client, 'course'));
    }

    public function test_end_before_start_re_renders_the_form_with_422(): void
    {
        self::mockTime('2026-03-01 10:00:00');
        $client = self::createClient();
        $this->logInTeacher($client);

        $crawler = $client->request('GET', '/courses/new');
        $client->submit($crawler->selectButton('Create course')->form(), $this->courseFields(['course[endsOn]' => '2026-02-23']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The last lesson cannot be before the first one.', (string) $client->getResponse()->getContent());
        self::assertSame(0, self::countRows($client, 'course'));
    }

    public function test_location_is_prefilled_from_the_last_course(): void
    {
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        CourseFactory::createOne(['teacher' => $teacher, 'location' => 'Studio Nord']);

        $crawler = $client->request('GET', '/courses/new');

        self::assertResponseIsSuccessful();
        self::assertSame('Studio Nord', $crawler->filter('input[name="course[location]"]')->attr('value'));
        self::assertStringContainsString('Filled in from your last course', (string) $client->getResponse()->getContent());
    }

    public function test_edit_saves_the_details_and_leaves_the_lessons(): void
    {
        self::mockTime('2026-03-01 10:00:00');
        $client = self::createClient();
        $this->logInTeacher($client);
        $id = $this->createCourse($client, ['course[name]' => 'Salsa Beginners']);
        $startsBefore = $this->lessonStarts($client, $id);

        $crawler = $client->request('GET', self::coursePath($id, '/edit'));
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Save changes')->form(), [
            'course[name]' => 'Salsa Improvers',
            'course[localStartTime]' => '19:30',
            'course[location]' => 'Hall B',
        ]);

        self::assertResponseRedirects(self::coursePath($id), 303);
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Salsa Improvers');
        self::assertCount(26, $startsBefore);
        self::assertSame($startsBefore, $this->lessonStarts($client, $id));
    }

    public function test_archive_removes_the_course_from_the_list_and_freezes_it(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        $course = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Monday Salsa', 'weekday' => 1]);
        $lesson = LessonSessionFactory::createOne([
            'course' => $course,
            'startsAtUtc' => new \DateTimeImmutable('2026-03-09 18:00:00'),
            'endsAtUtc' => new \DateTimeImmutable('2026-03-09 19:00:00'),
        ]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course]);
        $student = StudentFactory::createOne(['teacher' => $teacher]);
        $id = $course->getId();
        $lessonPath = self::coursePath($id, '/lessons/'.$lesson->getId()->toRfc4122());
        $enrollmentPath = self::coursePath($id, '/enrollments/'.$enrollment->getId()->toRfc4122());

        // The page's forms, taken before the course is archived (valid tokens).
        $page = $client->request('GET', self::coursePath($id));
        $stale = [
            [self::formFor($page, self::coursePath($id, '/extend')), []],
            [self::formFor($page, self::coursePath($id, '/lessons')), [
                ConfirmActionType::formName('add_lesson', $id).'[date]' => '2026-03-12',
                ConfirmActionType::formName('add_lesson', $id).'[time]' => '19:00',
            ]],
            [self::formFor($page, $lessonPath.'/cancel'), []],
            [self::formFor($page, self::coursePath($id, '/enrollments')), [
                ConfirmActionType::formName('enroll', $id).'[student]' => $student->getId()->toRfc4122(),
            ]],
            [self::formFor($page, $enrollmentPath.'/label'), [ConfirmActionType::formName('label', $enrollment->getId()).'[label]' => 'Zoë']],
            [self::formFor($page, $enrollmentPath.'/remove'), []],
        ];

        $crawler = $client->request('GET', self::coursePath($id, '/edit'));
        $client->submit($crawler->selectButton('Archive course')->form());

        self::assertResponseRedirects('/courses', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-region .toast', 'Course “Monday Salsa” archived.');
        self::assertSelectorTextContains('.empty-state', 'No courses yet');

        $crawler = $client->request('GET', self::coursePath($id));
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.course-page form'));

        $audits = self::countRows($client, 'audit_event');
        foreach ($stale as [$form, $values]) {
            $client->submit($form, $values);
            self::followToFlash($client, self::coursePath($id), 'error', 'This course is archived and cannot be changed.');
        }
        self::assertSame($audits, self::countRows($client, 'audit_event'));
        self::assertSame(1, self::countWhere($client, 'lesson_session', 'course_id', $id));
    }

    /**
     * Submits the new-course form and returns the new course's id (from the 303).
     *
     * @param array<string, string> $overrides
     */
    private function createCourse(KernelBrowser $client, array $overrides = []): Uuid
    {
        $crawler = $client->request('GET', '/courses/new');
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Create course')->form(), $this->courseFields($overrides));

        self::assertResponseStatusCodeSame(303);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/courses/[0-9a-f-]{36}$#', $location);

        return Uuid::fromString(substr($location, \strlen('/courses/')));
    }

    /**
     * A valid course: Mondays 19:00, 60 minutes, from Monday 2 March 2026.
     *
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function courseFields(array $overrides = []): array
    {
        return $overrides + [
            'course[name]' => 'Salsa Beginners',
            'course[weekday]' => '1',
            'course[localStartTime]' => '19:00',
            'course[durationMinutes]' => '60',
            'course[location]' => 'Studio Nord',
            'course[startsOn]' => '2026-03-02',
        ];
    }

    /**
     * @return list<string>
     */
    private function lessonStarts(KernelBrowser $client, Uuid $courseId): array
    {
        /** @var list<string> $starts */
        $starts = self::db($client)->fetchFirstColumn(
            'SELECT starts_at_utc FROM lesson_session WHERE course_id = ? ORDER BY starts_at_utc',
            [$courseId->toBinary()],
        );

        return $starts;
    }
}
