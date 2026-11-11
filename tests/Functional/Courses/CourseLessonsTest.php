<?php

declare(strict_types=1);

namespace App\Tests\Functional\Courses;

use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Form\ConfirmActionType;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The lesson actions of the course page (CRS-3, CRS-4): extend, add, re-time, cancel, reinstate.
 * Now is Wednesday 4 March 2026; the course runs Mondays 19:00 Europe/Zurich (18:00 UTC).
 */
#[ResetDatabase]
final class CourseLessonsTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LogsInTeacher;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $this->client = self::createClient();
    }

    public function test_extend_adds_the_lessons_and_hides_the_button_when_nothing_is_left(): void
    {
        $course = $this->course(['endsOn' => new \DateTimeImmutable('2026-03-30')]);
        $path = self::coursePath($course->getId());

        $crawler = $this->client->request('GET', $path);
        self::assertSelectorTextContains('.lesson-extend button', 'Add lessons until 30 Mar 2026');
        $this->client->submit(self::formFor($crawler, $path.'/extend'));

        $crawler = self::followToFlash($this->client, $path, 'success', '4 lessons added');
        self::assertSame(['Mon 9 Mar', 'Mon 16 Mar', 'Mon 23 Mar', 'Mon 30 Mar'], $this->lessonDates($crawler));
        self::assertCount(0, $crawler->filter(\sprintf('form[action="%s/extend"]', $path)));
    }

    public function test_add_lesson_and_refuse_a_second_one_at_the_same_time(): void
    {
        $course = $this->course();
        $id = $course->getId();
        $path = self::coursePath($id);
        $name = ConfirmActionType::formName('add_lesson', $id);
        $values = [$name.'[date]' => '2026-03-12', $name.'[time]' => '19:00'];

        $crawler = $this->client->request('GET', $path);
        $this->client->submit(self::formFor($crawler, $path.'/lessons'), $values);
        $crawler = self::followToFlash($this->client, $path, 'success', 'Lesson on Thu 12 Mar added.');
        self::assertSame(['Thu 12 Mar'], $this->lessonDates($crawler));

        $this->client->submit(self::formFor($crawler, $path.'/lessons'), $values);
        $crawler = self::followToFlash($this->client, $path, 'error', 'The course already has a lesson at that time.');
        self::assertCount(1, $crawler->filter('.lesson-row'));
        self::assertSame(1, self::countWhere($this->client, 'lesson_session', 'course_id', $id));
    }

    public function test_reschedule_moves_the_lesson(): void
    {
        $course = $this->course();
        $lesson = $this->lesson($course);
        $path = self::coursePath($course->getId());
        $name = ConfirmActionType::formName('reschedule', $lesson->getId());

        $crawler = $this->client->request('GET', $path);
        $this->client->submit(self::formFor($crawler, $this->lessonPath($lesson, '/reschedule')), [
            $name.'[date]' => '2026-03-10',
            $name.'[time]' => '20:00',
        ]);

        $crawler = self::followToFlash($this->client, $path, 'success', 'Lesson moved to Tue 10 Mar.');
        self::assertSame(['Tue 10 Mar'], $this->lessonDates($crawler));
        self::assertSame('2026-03-10 19:00:00', $this->lessonColumn($lesson, 'starts_at_utc'));
    }

    public function test_cancel_with_a_reason_marks_the_lesson_cancelled(): void
    {
        $course = $this->course();
        $lesson = $this->lesson($course);
        $path = self::coursePath($course->getId());

        $crawler = $this->client->request('GET', $path);
        $this->client->submit(self::formFor($crawler, $this->lessonPath($lesson, '/cancel')), [
            ConfirmActionType::formName('cancel', $lesson->getId()).'[reason]' => 'Studio closed',
        ]);

        $crawler = self::followToFlash($this->client, $path, 'success', 'Lesson on Mon 9 Mar cancelled.');
        self::assertCount(1, $crawler->filter('.lesson-row--cancelled'));
        self::assertSame('cancelled', $this->lessonColumn($lesson, 'status'));
    }

    public function test_cancel_is_refused_for_a_lesson_with_attendance(): void
    {
        $course = $this->course();
        $lesson = $this->lesson($course);
        AttendanceFactory::createOne(['lessonSession' => $lesson]);
        $path = self::coursePath($course->getId());

        $crawler = $this->client->request('GET', $path);
        $this->client->submit(self::formFor($crawler, $this->lessonPath($lesson, '/cancel')));

        $crawler = self::followToFlash($this->client, $path, 'error', 'This lesson already has attendance and cannot be cancelled.');
        self::assertCount(1, $crawler->filter('.lesson-row--scheduled'));
        self::assertSame('scheduled', $this->lessonColumn($lesson, 'status'));
    }

    public function test_reinstate_brings_a_cancelled_lesson_back(): void
    {
        $course = $this->course();
        $lesson = $this->lesson($course, cancelled: true);
        $path = self::coursePath($course->getId());

        $crawler = $this->client->request('GET', $path);
        self::assertCount(1, $crawler->filter('.lesson-row--cancelled'));
        $this->client->submit(self::formFor($crawler, $this->lessonPath($lesson, '/reinstate')));

        $crawler = self::followToFlash($this->client, $path, 'success', 'Lesson on Mon 9 Mar reinstated.');
        self::assertCount(1, $crawler->filter('.lesson-row--scheduled'));
        self::assertSame('scheduled', $this->lessonColumn($lesson, 'status'));
    }

    /**
     * A course of the logged-in teacher: Mondays 19:00 Europe/Zurich, from Monday 2 March 2026.
     *
     * @param array<string, mixed> $attributes
     */
    private function course(array $attributes = []): Course
    {
        $teacher = $this->logInTeacher($this->client);

        return CourseFactory::createOne($attributes + [
            'teacher' => $teacher,
            'timezone' => 'Europe/Zurich',
            'weekday' => 1,
            'startsOn' => new \DateTimeImmutable('2026-03-02'),
        ]);
    }

    /**
     * Monday 9 March 2026, 19:00 Europe/Zurich.
     */
    private function lesson(Course $course, bool $cancelled = false): LessonSession
    {
        $factory = LessonSessionFactory::new([
            'course' => $course,
            'startsAtUtc' => new \DateTimeImmutable('2026-03-09 18:00:00'),
            'endsAtUtc' => new \DateTimeImmutable('2026-03-09 19:00:00'),
        ]);

        return ($cancelled ? $factory->cancelled() : $factory)->create();
    }

    private function lessonPath(LessonSession $lesson, string $action): string
    {
        return self::coursePath($lesson->getCourse()->getId(), '/lessons/'.$lesson->getId()->toRfc4122().$action);
    }

    private function lessonColumn(LessonSession $lesson, string $column): string
    {
        return (string) self::db($this->client)->fetchOne(
            \sprintf('SELECT %s FROM lesson_session WHERE id = ?', $column),
            [$lesson->getId()->toBinary()],
        );
    }

    /**
     * @return list<string>
     */
    private function lessonDates(Crawler $crawler): array
    {
        return $crawler->filter('.lesson-row__date')->each(static fn ($date): string => trim($date->text()));
    }
}
