<?php

declare(strict_types=1);

namespace App\Tests\Functional\Credits;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Credits\ReadModel\Balances;
use App\Tests\Factory\AttendanceFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\LogsInTeacher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * Helpers for the credit page tests (#12): log in a factory teacher, build an enrollment with a
 * given balance, the paths of the three credit routes, the balance through Balances, the flash
 * toast and DBAL row counts. Use in a WebTestCase with `#[ResetDatabase]`, after `createClient()`.
 */
trait CreditPages
{
    use LogsInTeacher;

    /**
     * An active enrollment of a new student of `$teacher` in `$course` (or a new course named
     * `$courseName`) whose balance is `$balance`: one grant of `$balance` when positive, or
     * `-$balance` present attendances at completed lessons when negative.
     */
    private static function enrolled(Teacher $teacher, string $firstName, string $lastName, int $balance = 0, ?Course $course = null, string $courseName = 'Salsa Beginners'): Enrollment
    {
        $course ??= CourseFactory::createOne(['teacher' => $teacher, 'name' => $courseName]);
        $student = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => $firstName, 'lastName' => $lastName]);
        $enrollment = EnrollmentFactory::createOne(['course' => $course, 'student' => $student]);
        if ($balance > 0) {
            CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => $balance]);
        }
        for ($i = 0; $i < -$balance; ++$i) {
            AttendanceFactory::createOne([
                'lessonSession' => LessonSessionFactory::new(['course' => $course])->completed()->create(),
                'student' => $student,
            ]);
        }

        return $enrollment;
    }

    private static function grantPath(Enrollment $enrollment, ?string $from = null): string
    {
        return \sprintf(
            '/courses/%s/enrollments/%s/credits%s',
            $enrollment->getCourse()->getId()->toRfc4122(),
            $enrollment->getId()->toRfc4122(),
            null === $from ? '' : '?from='.rawurlencode($from),
        );
    }

    /**
     * `credit_void` or `credit_correct` (`$action` 'void' | 'correct') of a grant under a student.
     */
    private static function grantActionPath(string $action, Uuid $studentId, Uuid $grantId): string
    {
        return \sprintf('/students/%s/credits/%s/%s', $studentId->toRfc4122(), $grantId->toRfc4122(), $action);
    }

    private static function coursePath(Enrollment $enrollment): string
    {
        return '/courses/'.$enrollment->getCourse()->getId()->toRfc4122();
    }

    private static function studentPath(Enrollment $enrollment): string
    {
        return '/students/'.$enrollment->getStudent()->getId()->toRfc4122();
    }

    private static function balanceOf(KernelBrowser $client, Teacher $teacher, Enrollment $enrollment): int
    {
        $balances = $client->getContainer()->get(Balances::class);
        self::assertInstanceOf(Balances::class, $balances);
        $balance = $balances->forEnrollment($teacher, $enrollment->getId());
        self::assertNotNull($balance);

        return $balance->balance;
    }

    /**
     * Follows the 303 to `$path` and asserts the success toast.
     */
    private static function followToToast(KernelBrowser $client, string $path, string $text): Crawler
    {
        self::assertResponseRedirects($path, 303);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-region .toast', $text);

        return $crawler;
    }

    /**
     * The roster row (`tr.roster-row`) of the student with this full name.
     */
    private static function rosterRow(Crawler $crawler, string $name): Crawler
    {
        $row = $crawler->filter('tr.roster-row')->reduce(static fn (Crawler $tr): bool => str_contains($tr->filter('.roster-row__name')->text(), $name));
        self::assertCount(1, $row, \sprintf('One roster row for %s.', $name));

        return $row;
    }

    private static function db(KernelBrowser $client): Connection
    {
        $connection = $client->getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private static function countRows(KernelBrowser $client, string $table): int
    {
        return (int) self::db($client)->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }

    private static function auditCount(KernelBrowser $client, string $action): int
    {
        return (int) self::db($client)->fetchOne('SELECT COUNT(*) FROM audit_event WHERE action = ?', [$action]);
    }
}
