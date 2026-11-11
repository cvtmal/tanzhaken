<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Form\ConfirmActionType;
use App\Students\Entity\Student;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\Courses\CoursePages;
use App\Tests\Functional\LogsInTeacher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The 404 rule (S4) and the request rules of the #13 routes, as #10's CourseOwnershipTest: another
 * teacher's course, lesson or student, a lesson of another course, a malformed id; a POST without
 * a valid token changes nothing; GET on a POST route is 405; anonymous goes to the login.
 */
#[ResetDatabase]
final class LessonOwnershipTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;

    private const NOT_A_UUID = 'not-a-uuid';

    private KernelBrowser $client;
    private LessonSession $ownLesson;
    private Student $ownStudent;
    private LessonSession $otherOwnLesson;
    private Student $otherOwnStudent;
    private Course $foreign;
    private LessonSession $foreignLesson;
    private Student $foreignStudent;

    protected function setUp(): void
    {
        self::mockTime('2026-11-10 19:30:00');
        $this->client = self::createClient();
        $teacher = $this->logInTeacher($this->client);

        [$this->ownLesson, $this->ownStudent] = $this->courseWithChildren(CourseFactory::createOne(['teacher' => $teacher]));
        [$this->otherOwnLesson, $this->otherOwnStudent] = $this->courseWithChildren(CourseFactory::createOne(['teacher' => $teacher]));
        $this->foreign = CourseFactory::createOne(['teacher' => TeacherFactory::createOne()]);
        [$this->foreignLesson, $this->foreignStudent] = $this->courseWithChildren($this->foreign);
    }

    /**
     * Every #13 route: method, path, the form's action ('' for GET).
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield 'lesson_show' => ['GET', '/courses/{id}/lessons/{lessonId}', ''];
        yield from self::postRoutes();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function postRoutes(): iterable
    {
        yield 'lesson_mark' => ['POST', '/courses/{id}/lessons/{lessonId}/attendance/{studentId}', 'mark'];
        yield 'lesson_complete' => ['POST', '/courses/{id}/lessons/{lessonId}/complete', 'complete'];
    }

    #[DataProvider('routes')]
    public function test_another_teachers_course_answers_404(string $method, string $template, string $action): void
    {
        $token = $this->token();
        $before = $this->snapshot();

        $this->client->request($method, $this->path($template, $this->foreign, $this->foreignLesson, $this->foreignStudent), $this->body($action, $token, $this->foreignLesson, $this->foreignStudent));

        self::assertResponseStatusCodeSame(404);
        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('routes')]
    public function test_a_lesson_of_another_course_answers_404(string $method, string $template, string $action): void
    {
        $token = $this->token();
        $before = $this->snapshot();
        $own = $this->ownLesson->getCourse();

        $this->client->request($method, $this->path($template, $own, $this->foreignLesson, $this->ownStudent), $this->body($action, $token, $this->foreignLesson, $this->ownStudent));
        self::assertResponseStatusCodeSame(404);

        $this->client->request($method, $this->path($template, $own, $this->otherOwnLesson, $this->otherOwnStudent), $this->body($action, $token, $this->otherOwnLesson, $this->otherOwnStudent));
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, $this->snapshot());
    }

    public function test_another_teachers_student_answers_404_with_a_valid_token(): void
    {
        $token = $this->token();
        $before = $this->snapshot();

        foreach (['present', 'absent', 'clear'] as $button) {
            $this->client->request('POST', self::markPath($this->ownLesson, $this->foreignStudent), [
                ConfirmActionType::formName('mark', $this->foreignStudent->getId()) => [$button => '', 'reason' => '', '_token' => $token],
            ]);
            self::assertResponseStatusCodeSame(404);
        }

        self::assertSame($before, $this->snapshot());
    }

    #[DataProvider('routes')]
    public function test_a_malformed_id_answers_404(string $method, string $template, string $action): void
    {
        $own = $this->ownLesson->getCourse()->getId()->toRfc4122();
        $lesson = $this->ownLesson->getId()->toRfc4122();
        $student = $this->ownStudent->getId()->toRfc4122();

        $paths = [
            strtr($template, ['{id}' => self::NOT_A_UUID, '{lessonId}' => $lesson, '{studentId}' => $student]),
            strtr($template, ['{id}' => $own, '{lessonId}' => self::NOT_A_UUID, '{studentId}' => $student]),
        ];
        if (str_contains($template, '{studentId}')) {
            $paths[] = strtr($template, ['{id}' => $own, '{lessonId}' => $lesson, '{studentId}' => self::NOT_A_UUID]);
        }

        foreach ($paths as $path) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    #[DataProvider('postRoutes')]
    public function test_a_post_without_a_valid_token_changes_nothing(string $method, string $template, string $action): void
    {
        $path = $this->path($template, $this->ownLesson->getCourse(), $this->ownLesson, $this->ownStudent);
        $target = 'mark' === $action ? self::rowTarget($this->ownLesson, $this->ownStudent) : self::lessonPath($this->ownLesson);
        $before = $this->snapshot();

        foreach (['', 'invalid'] as $token) {
            $body = '' === $token ? [] : $this->body($action, $token, $this->ownLesson, $this->ownStudent);
            $this->client->request($method, $path, $body);

            self::assertResponseRedirects($target, 303);
            $this->client->followRedirect();
            self::assertSelectorExists('.flash-region .alert--danger');
            self::assertSame($before, $this->snapshot());
        }
    }

    #[DataProvider('postRoutes')]
    public function test_get_on_a_post_route_answers_405(string $method, string $template, string $action): void
    {
        $this->client->request('GET', $this->path($template, $this->ownLesson->getCourse(), $this->ownLesson, $this->ownStudent));

        self::assertResponseStatusCodeSame(405);
    }

    #[DataProvider('routes')]
    public function test_anonymous_is_sent_to_the_login(string $method, string $template, string $action): void
    {
        $this->client->getCookieJar()->clear();
        $before = $this->snapshot();

        $this->client->request($method, $this->path($template, $this->ownLesson->getCourse(), $this->ownLesson, $this->ownStudent));

        self::assertResponseRedirects('/login');
        self::assertSame($before, $this->snapshot());
    }

    /**
     * A started lesson with one actively enrolled student.
     *
     * @return array{LessonSession, Student}
     */
    private function courseWithChildren(Course $course): array
    {
        $enrollment = EnrollmentFactory::createOne(['course' => $course]);

        return [self::lessonAt($course, '2026-11-10 18:00:00'), $enrollment->getStudent()];
    }

    /**
     * A valid stateless form token, read from the own lesson page.
     */
    private function token(): string
    {
        $crawler = $this->client->request('GET', self::lessonPath($this->ownLesson));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form.lesson-page__complete input[name$="[_token]"]')->attr('value');
        self::assertNotSame('', $token);

        return $token;
    }

    /**
     * The POST body of a valid submit of `$action` (present for a mark), with `$token`.
     *
     * @return array<string, array<string, string>>
     */
    private function body(string $action, string $token, LessonSession $lesson, Student $student): array
    {
        return match ($action) {
            'mark' => [ConfirmActionType::formName('mark', $student->getId()) => ['present' => '', 'reason' => '', '_token' => $token]],
            'complete' => [ConfirmActionType::formName('complete', $lesson->getId()) => ['_token' => $token]],
            default => [],
        };
    }

    private function path(string $template, Course $course, LessonSession $lesson, Student $student): string
    {
        return strtr($template, [
            '{id}' => $course->getId()->toRfc4122(),
            '{lessonId}' => $lesson->getId()->toRfc4122(),
            '{studentId}' => $student->getId()->toRfc4122(),
        ]);
    }

    /**
     * Row counts that any #13 write moves.
     *
     * @return array<string, int>
     */
    private function snapshot(): array
    {
        return [
            'attendance' => self::countRows($this->client, 'attendance'),
            'audit_event' => self::countRows($this->client, 'audit_event'),
            'completed' => (int) self::db($this->client)->fetchOne("SELECT COUNT(*) FROM lesson_session WHERE status = 'completed'"),
        ];
    }
}
