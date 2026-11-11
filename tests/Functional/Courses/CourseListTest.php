<?php

declare(strict_types=1);

namespace App\Tests\Functional\Courses;

use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The course list (CRS-1, C1/C2): own active courses only, in weekday order, the empty state.
 */
#[ResetDatabase]
final class CourseListTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogsInTeacher;

    public function test_anonymous_visitor_is_sent_to_login(): void
    {
        $client = self::createClient();
        $client->request('GET', '/courses');

        self::assertResponseRedirects('/login');
    }

    public function test_lists_own_active_courses_in_weekday_order(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Friday Tango', 'weekday' => 5]);
        CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Monday Salsa', 'weekday' => 1]);
        CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Wednesday Swing', 'weekday' => 3]);
        CourseFactory::new(['teacher' => $teacher, 'name' => 'Old Bachata', 'weekday' => 2])->archived()->create();
        CourseFactory::createOne(['teacher' => TeacherFactory::createOne(), 'name' => 'Foreign Zouk', 'weekday' => 1]);

        $crawler = $client->request('GET', '/courses');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Monday Salsa', 'Wednesday Swing', 'Friday Tango'],
            $crawler->filter('.list-row__link')->each(static fn ($link): string => trim($link->text())),
        );
        self::assertSelectorNotExists('.empty-state');
    }

    public function test_teacher_without_courses_sees_the_empty_state(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);

        $crawler = $client->request('GET', '/courses');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'No courses yet');
        self::assertCount(0, $crawler->filter('.list-row__link'));
        self::assertSame('/courses/new', $crawler->filter('.empty-state a')->attr('href'));
    }

    public function test_card_shows_student_count_and_next_lesson(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        $course = CourseFactory::createOne([
            'teacher' => $teacher,
            'name' => 'Monday Salsa',
            'weekday' => 1,
            'location' => 'Studio Nord',
            'startsOn' => new \DateTimeImmutable('2026-03-02'),
        ]);
        EnrollmentFactory::createOne(['course' => $course]);
        EnrollmentFactory::createOne(['course' => $course]);
        LessonSessionFactory::createOne([
            'course' => $course,
            'startsAtUtc' => new \DateTimeImmutable('2026-03-09 18:00:00'),
            'endsAtUtc' => new \DateTimeImmutable('2026-03-09 19:00:00'),
        ]);

        $client->request('GET', '/courses');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.list-row', '2 students · Studio Nord');
        self::assertSelectorTextContains('.list-row', 'Next lesson Mon 9 Mar');
    }
}
