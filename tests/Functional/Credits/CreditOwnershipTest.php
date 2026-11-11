<?php

declare(strict_types=1);

namespace App\Tests\Functional\Credits;

use App\Courses\Entity\Enrollment;
use App\Credits\Entity\CreditGrant;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\TeacherFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The 404 rule (S4, SEC-2) on the three credit routes: another teacher's enrollment or grant, an
 * enrollment of another course under `{id}`, a grant of another student under `{id}` and a
 * malformed id answer 404 on GET and POST and write nothing; an anonymous visitor goes to /login.
 */
#[ResetDatabase]
final class CreditOwnershipTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CreditPages;

    private const NOT_A_UUID = 'not-a-uuid';

    private KernelBrowser $client;
    private Enrollment $own;
    private CreditGrant $ownGrant;
    private Enrollment $otherOwn;
    private CreditGrant $otherOwnGrant;
    private Enrollment $foreign;
    private CreditGrant $foreignGrant;

    protected function setUp(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $this->client = self::createClient();
        $teacher = $this->logInTeacher($this->client);

        $this->own = self::enrolled($teacher, 'Alex', 'Meier');
        $this->ownGrant = CreditGrantFactory::createOne(['enrollment' => $this->own]);
        $this->otherOwn = self::enrolled($teacher, 'Sam', 'Keller', courseName: 'Tango Basics');
        $this->otherOwnGrant = CreditGrantFactory::createOne(['enrollment' => $this->otherOwn]);
        $this->foreign = self::enrolled(TeacherFactory::createOne(), 'Robin', 'Huber');
        $this->foreignGrant = CreditGrantFactory::createOne(['enrollment' => $this->foreign]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'POST' => ['POST'];
    }

    #[DataProvider('methods')]
    public function test_another_teachers_enrollment_or_grant_answers_404(string $method): void
    {
        $this->assertNotFound($method, [
            self::grantPath($this->foreign),
            self::grantActionPath('void', $this->foreign->getStudent()->getId(), $this->foreignGrant->getId()),
            self::grantActionPath('correct', $this->foreign->getStudent()->getId(), $this->foreignGrant->getId()),
            // Own ids around a foreign one.
            self::courseEnrollmentPath($this->own, $this->foreign),
            self::grantActionPath('void', $this->own->getStudent()->getId(), $this->foreignGrant->getId()),
            self::grantActionPath('correct', $this->own->getStudent()->getId(), $this->foreignGrant->getId()),
        ]);
    }

    #[DataProvider('methods')]
    public function test_an_enrollment_of_another_course_answers_404(string $method): void
    {
        $this->assertNotFound($method, [self::courseEnrollmentPath($this->own, $this->otherOwn)]);
    }

    #[DataProvider('methods')]
    public function test_a_grant_of_another_student_answers_404(string $method): void
    {
        $this->assertNotFound($method, [
            self::grantActionPath('void', $this->own->getStudent()->getId(), $this->otherOwnGrant->getId()),
            self::grantActionPath('correct', $this->own->getStudent()->getId(), $this->otherOwnGrant->getId()),
        ]);
    }

    #[DataProvider('methods')]
    public function test_a_malformed_id_answers_404(string $method): void
    {
        $course = $this->own->getCourse()->getId()->toRfc4122();
        $student = $this->own->getStudent()->getId()->toRfc4122();
        $grant = $this->ownGrant->getId()->toRfc4122();

        $this->assertNotFound($method, [
            \sprintf('/courses/%s/enrollments/%s/credits', self::NOT_A_UUID, $this->own->getId()->toRfc4122()),
            \sprintf('/courses/%s/enrollments/%s/credits', $course, self::NOT_A_UUID),
            \sprintf('/students/%s/credits/%s/void', self::NOT_A_UUID, $grant),
            \sprintf('/students/%s/credits/%s/void', $student, self::NOT_A_UUID),
            \sprintf('/students/%s/credits/%s/correct', self::NOT_A_UUID, $grant),
            \sprintf('/students/%s/credits/%s/correct', $student, self::NOT_A_UUID),
        ]);
    }

    public function test_an_anonymous_visitor_is_sent_to_login(): void
    {
        $anonymous = $this->client;
        $anonymous->restart(); // drops the session cookie of the logged-in teacher

        foreach ([
            self::grantPath($this->own),
            self::grantActionPath('void', $this->own->getStudent()->getId(), $this->ownGrant->getId()),
            self::grantActionPath('correct', $this->own->getStudent()->getId(), $this->ownGrant->getId()),
        ] as $path) {
            $anonymous->request('GET', $path);
            self::assertResponseRedirects('/login');
        }
    }

    /**
     * Each path answers 404 to `$method` and nothing is written.
     *
     * @param list<string> $paths
     */
    private function assertNotFound(string $method, array $paths): void
    {
        $before = [self::countRows($this->client, 'credit_grant'), self::countRows($this->client, 'audit_event')];

        foreach ($paths as $path) {
            $this->client->request($method, $path);
            self::assertResponseStatusCodeSame(404, $method.' '.$path);
        }

        self::assertSame($before, [self::countRows($this->client, 'credit_grant'), self::countRows($this->client, 'audit_event')]);
        self::assertSame(0, (int) self::db($this->client)->fetchOne('SELECT COUNT(*) FROM credit_grant WHERE voided_at IS NOT NULL'));
    }

    /**
     * The grant path of `$enrollment` under the course of `$course`.
     */
    private static function courseEnrollmentPath(Enrollment $course, Enrollment $enrollment): string
    {
        return \sprintf('/courses/%s/enrollments/%s/credits', $course->getCourse()->getId()->toRfc4122(), $enrollment->getId()->toRfc4122());
    }
}
