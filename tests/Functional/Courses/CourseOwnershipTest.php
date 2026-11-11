<?php

declare(strict_types=1);

namespace App\Tests\Functional\Courses;

use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Courses\Entity\LessonSession;
use App\Courses\Form\ConfirmActionType;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The 404 rule (P2, S4) and the request rules of every #10 route with an id: another teacher's
 * course, a lesson or enrollment that is not the course's, a malformed id; a POST without a
 * valid token changes nothing; GET on a POST route is 405.
 */
#[ResetDatabase]
final class CourseOwnershipTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LogsInTeacher;

    private const NOT_A_UUID = 'not-a-uuid';

    private KernelBrowser $client;
    private Course $own;
    private LessonSession $ownLesson;
    private Enrollment $ownEnrollment;
    private Uuid $freeStudent;
    private LessonSession $otherOwnLesson;
    private Enrollment $otherOwnEnrollment;
    private Course $foreign;
    private LessonSession $foreignLesson;
    private Enrollment $foreignEnrollment;

    protected function setUp(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $this->client = self::createClient();
        $teacher = $this->logInTeacher($this->client);

        [$this->own, $this->ownLesson, $this->ownEnrollment] = $this->courseWithChildren(CourseFactory::createOne(['teacher' => $teacher]));
        [, $this->otherOwnLesson, $this->otherOwnEnrollment] = $this->courseWithChildren(CourseFactory::createOne(['teacher' => $teacher]));
        [$this->foreign, $this->foreignLesson, $this->foreignEnrollment] = $this->courseWithChildren(CourseFactory::createOne(['teacher' => TeacherFactory::createOne()]));
        $this->freeStudent = StudentFactory::createOne(['teacher' => $teacher])->getId();
    }

    /**
     * Every #10 route with an id: method, path, the form name's action and its id ('course' |
     * 'lesson' | 'enrollment'), the status of an invalid POST.
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function routesWithId(): iterable
    {
        yield 'course_show' => ['GET', '/courses/{id}', '', 'course'];
        yield 'course_edit GET' => ['GET', '/courses/{id}/edit', '', 'course'];
        yield 'course_edit POST' => ['POST', '/courses/{id}/edit', 'course', 'course'];
        yield from self::postRoutes();
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function postRoutes(): iterable
    {
        yield 'course_archive' => ['POST', '/courses/{id}/archive', 'archive', 'course'];
        yield 'course_extend' => ['POST', '/courses/{id}/extend', 'extend', 'course'];
        yield 'course_lesson_add' => ['POST', '/courses/{id}/lessons', 'add_lesson', 'course'];
        yield 'course_lesson_reschedule' => ['POST', '/courses/{id}/lessons/{lessonId}/reschedule', 'reschedule', 'lesson'];
        yield 'course_lesson_cancel' => ['POST', '/courses/{id}/lessons/{lessonId}/cancel', 'cancel', 'lesson'];
        yield 'course_lesson_reinstate' => ['POST', '/courses/{id}/lessons/{lessonId}/reinstate', 'reinstate', 'lesson'];
        yield 'course_enroll' => ['POST', '/courses/{id}/enrollments', 'enroll', 'course'];
        yield 'course_enrollment_remove' => ['POST', '/courses/{id}/enrollments/{enrollmentId}/remove', 'remove', 'enrollment'];
        yield 'course_enrollment_label' => ['POST', '/courses/{id}/enrollments/{enrollmentId}/label', 'label', 'enrollment'];
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function childRoutes(): iterable
    {
        foreach (self::postRoutes() as $name => $route) {
            if ('course' !== $route[3]) {
                yield $name => $route;
            }
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function postRoutesWithNew(): iterable
    {
        yield 'course_new POST' => ['POST', '/courses/new', 'course', 'course'];
        yield 'course_edit POST' => ['POST', '/courses/{id}/edit', 'course', 'course'];
        yield from self::postRoutes();
    }

    #[DataProvider('routesWithId')]
    public function test_another_teachers_course_answers_404(string $method, string $template, string $action, string $idKind): void
    {
        $this->client->request($method, $this->path($template, $this->foreign, $this->foreignLesson, $this->foreignEnrollment));

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('childRoutes')]
    public function test_a_lesson_or_enrollment_of_another_course_answers_404(string $method, string $template, string $action, string $idKind): void
    {
        $audits = self::countRows($this->client, 'audit_event');

        $this->client->request($method, $this->path($template, $this->own, $this->foreignLesson, $this->foreignEnrollment));
        self::assertResponseStatusCodeSame(404);

        $this->client->request($method, $this->path($template, $this->own, $this->otherOwnLesson, $this->otherOwnEnrollment));
        self::assertResponseStatusCodeSame(404);

        self::assertSame($audits, self::countRows($this->client, 'audit_event'));
    }

    #[DataProvider('routesWithId')]
    public function test_a_malformed_id_answers_404(string $method, string $template, string $action, string $idKind): void
    {
        $this->client->request($method, strtr($template, ['{id}' => self::NOT_A_UUID, '{lessonId}' => self::NOT_A_UUID, '{enrollmentId}' => self::NOT_A_UUID]));
        self::assertResponseStatusCodeSame(404);

        if (str_contains($template, '{lessonId}') || str_contains($template, '{enrollmentId}')) {
            $this->client->request($method, strtr($template, [
                '{id}' => $this->own->getId()->toRfc4122(),
                '{lessonId}' => self::NOT_A_UUID,
                '{enrollmentId}' => self::NOT_A_UUID,
            ]));
            self::assertResponseStatusCodeSame(404);
        }
    }

    #[DataProvider('postRoutesWithNew')]
    public function test_a_post_without_a_valid_token_changes_nothing(string $method, string $template, string $action, string $idKind): void
    {
        $path = $this->path($template, $this->own, $this->ownLesson, $this->ownEnrollment);
        $name = 'course' === $action ? 'course' : ConfirmActionType::formName($action, match ($idKind) {
            'lesson' => $this->ownLesson->getId(),
            'enrollment' => $this->ownEnrollment->getId(),
            default => $this->own->getId(),
        });
        $fields = $this->fields($action);
        $before = $this->snapshot();

        foreach ([$fields, ['_token' => 'invalid'] + $fields] as $data) {
            $this->client->request($method, $path, [] === $data ? [] : [$name => $data]);

            if ('course' === $action) {
                self::assertResponseStatusCodeSame(422);
            } else {
                self::assertResponseRedirects(self::coursePath($this->own->getId()), 303);
                $this->client->followRedirect();
                self::assertSelectorExists('.flash-region .alert--danger');
            }
            self::assertSame($before, $this->snapshot());
        }
    }

    #[DataProvider('postRoutes')]
    public function test_get_on_a_post_route_answers_405(string $method, string $template, string $action, string $idKind): void
    {
        $this->client->request('GET', $this->path($template, $this->own, $this->ownLesson, $this->ownEnrollment));

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @return array{Course, LessonSession, Enrollment}
     */
    private function courseWithChildren(Course $course): array
    {
        return [
            $course,
            LessonSessionFactory::createOne(['course' => $course]),
            EnrollmentFactory::createOne(['course' => $course]),
        ];
    }

    private function path(string $template, Course $course, LessonSession $lesson, Enrollment $enrollment): string
    {
        return strtr($template, [
            '{id}' => $course->getId()->toRfc4122(),
            '{lessonId}' => $lesson->getId()->toRfc4122(),
            '{enrollmentId}' => $enrollment->getId()->toRfc4122(),
        ]);
    }

    /**
     * Fields a valid submit of the form would carry, without its token.
     *
     * @return array<string, string>
     */
    private function fields(string $action): array
    {
        return match ($action) {
            'course' => [
                'name' => 'Changed name',
                'weekday' => '2',
                'localStartTime' => '18:00',
                'durationMinutes' => '60',
                'startsOn' => '2026-03-03',
            ],
            'add_lesson', 'reschedule' => ['date' => '2026-03-20', 'time' => '19:00'],
            'cancel' => ['reason' => 'Studio closed'],
            'enroll' => ['student' => $this->freeStudent->toRfc4122()],
            'label' => ['label' => 'Zoë'],
            default => [],
        };
    }

    /**
     * Row counts that any change of #10 moves (every change writes an audit event).
     *
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        $counts = [];
        foreach (['audit_event', 'course', 'lesson_session', 'enrollment'] as $table) {
            $counts[$table] = self::countRows($this->client, $table);
        }
        $counts['cancelled'] = (int) self::db($this->client)->fetchOne("SELECT COUNT(*) FROM lesson_session WHERE status = 'cancelled'");
        $counts['left'] = (int) self::db($this->client)->fetchOne('SELECT COUNT(*) FROM enrollment WHERE left_at IS NOT NULL');

        return $counts;
    }
}
