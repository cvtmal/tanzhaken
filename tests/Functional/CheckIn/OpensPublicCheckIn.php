<?php

declare(strict_types=1);

namespace App\Tests\Functional\CheckIn;

use App\Account\Entity\Teacher;
use App\CheckIn\Entity\CheckInWindow;
use App\CheckIn\Service\CheckInThrottle;
use App\Courses\Entity\Enrollment;
use App\Courses\Entity\LessonSession;
use App\Credits\ReadModel\Balances;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\LessonSessionFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Clock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds an open public check-in for the functional tests of #14 (research Decision 11, plan P2).
 *
 * The window is created by the factory with the hash of a fixed token: `CheckInWindows::open()` is
 * not in the test container until #16 injects it. Use the helpers after `createClient()`, call
 * `resetCheckInLimits()` in `setUp()` (the limiters live in the filesystem `cache.rate_limiter`
 * pool, which outlives the rolled-back transaction of each test), and give the class
 * `#[ResetDatabase]`.
 */
trait OpensPublicCheckIn
{
    /** A well-formed path token (43 characters of [A-Za-z0-9_-]). */
    private const TOKEN = 'functional-test-aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TEACHER_NAME = 'Mara Lindqvist';
    private const COURSE_NAME = 'Salsa basics';
    private const COURSE_TIMEZONE = 'Europe/Berlin';

    private function resetCheckInLimits(KernelBrowser $client): void
    {
        $pool = $client->getContainer()->get('cache.rate_limiter');
        \assert($pool instanceof CacheItemPoolInterface);
        $pool->clear();
    }

    /**
     * Consumes a limiter of CheckInThrottle until it refuses (plan P3).
     *
     * @param \Closure(CheckInThrottle): ?\DateTimeImmutable $consume
     */
    private static function exhaustLimit(\Closure $consume): void
    {
        $throttle = static::getContainer()->get(CheckInThrottle::class);
        \assert($throttle instanceof CheckInThrottle);
        for ($attempt = 0; $attempt < 1_000; ++$attempt) {
            if (null !== $consume($throttle)) {
                return;
            }
        }

        Assert::fail('The limiter never refused.');
    }

    /**
     * A teacher, a course in Europe/Berlin, a lesson today at 18:00 UTC, one enrollment per entry
     * of $students (student attributes; the optional key `credits` is the size of its grant, 0 for
     * none, default 10) and an active window for self::TOKEN.
     *
     * @param list<array<string, mixed>> $students
     * @param array<string, mixed>       $teacher
     *
     * @return array{Teacher, LessonSession, list<Enrollment>}
     */
    private static function openPublicCheckIn(
        array $students = [['firstName' => 'Anna', 'lastName' => 'Berg'], ['firstName' => 'Ben', 'lastName' => 'Kowalski']],
        array $teacher = [],
    ): array {
        [$owner, $lesson, $enrollments] = self::lessonWithStudents($students, $teacher);
        self::openWindow($lesson);

        return [$owner, $lesson, $enrollments];
    }

    /**
     * @param list<array<string, mixed>> $students
     * @param array<string, mixed>       $teacher
     *
     * @return array{Teacher, LessonSession, list<Enrollment>}
     */
    private static function lessonWithStudents(array $students, array $teacher = []): array
    {
        $owner = TeacherFactory::createOne(['name' => self::TEACHER_NAME] + $teacher);
        $course = CourseFactory::createOne(['teacher' => $owner, 'name' => self::COURSE_NAME, 'timezone' => self::COURSE_TIMEZONE]);
        $startsAt = Clock::get()->now()->setTime(18, 0);
        $lesson = LessonSessionFactory::createOne(['course' => $course, 'startsAtUtc' => $startsAt, 'endsAtUtc' => $startsAt->modify('+60 minutes')]);

        $enrollments = [];
        foreach ($students as $attributes) {
            $credits = $attributes['credits'] ?? 10;
            unset($attributes['credits']);
            $enrollment = EnrollmentFactory::createOne([
                'course' => $course,
                'student' => StudentFactory::createOne(['teacher' => $owner] + $attributes),
            ]);
            if ($credits > 0) {
                CreditGrantFactory::createOne(['enrollment' => $enrollment, 'quantity' => $credits]);
            }
            $enrollments[] = $enrollment;
        }

        return [$owner, $lesson, $enrollments];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function openWindow(LessonSession $lesson, string $token = self::TOKEN, array $attributes = []): CheckInWindow
    {
        return CheckInWindowFactory::createOne(['lessonSession' => $lesson, 'tokenHash' => hash('sha256', $token)] + $attributes);
    }

    /**
     * The lesson start as the page prints it in the course's timezone (D-042): "Tue 10 Nov, 7:00 PM",
     * the time in ICU's short `en` format (which may put U+202F before "PM"), so built with ICU too.
     */
    private static function lessonTime(LessonSession $lesson): string
    {
        $zone = new \DateTimeZone(self::COURSE_TIMEZONE);
        $date = new \IntlDateFormatter('en', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone, null, 'EEE d MMM');
        $time = new \IntlDateFormatter('en', \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, $zone);
        $startsAt = $lesson->getStartsAtUtc();

        return $date->format($startsAt).', '.$time->format($startsAt);
    }

    private static function attendanceCount(): int
    {
        return (int) self::connection()->fetchOne('SELECT COUNT(*) FROM attendance');
    }

    /**
     * @return list<array{status: string, source: string}>
     */
    private static function attendanceRows(): array
    {
        /** @var list<array{status: string, source: string}> $rows */
        $rows = self::connection()->fetchAllAssociative('SELECT status, source FROM attendance');

        return $rows;
    }

    private static function balanceOf(Enrollment $enrollment): ?int
    {
        $balances = static::getContainer()->get(Balances::class);
        \assert($balances instanceof Balances);

        return $balances->forEnrollment($enrollment->getCourse()->getTeacher(), $enrollment->getId())?->balance;
    }

    private static function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * POSTs the confirm step of $token; $enrollment null sends no field.
     *
     * @param array<string, string> $server
     */
    private static function confirm(KernelBrowser $client, ?string $enrollment, string $token = self::TOKEN, array $server = []): Crawler
    {
        return $client->request('POST', '/c/'.$token.'/confirm', null === $enrollment ? [] : ['enrollment' => $enrollment], [], $server);
    }

    /**
     * S8 on the last response: the three headers, both meta tags, and no cookie (D-041, plan P5).
     */
    private static function assertPublicResponse(KernelBrowser $client): void
    {
        $response = $client->getResponse();
        Assert::assertInstanceOf(Response::class, $response);
        Assert::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        Assert::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        Assert::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        Assert::assertFalse($response->headers->has('Set-Cookie'), 'A public check-in response sets no cookie.');
        Assert::assertSame([], $response->headers->getCookies());

        $crawler = $client->getCrawler();
        Assert::assertCount(1, $crawler->filter('meta[name="robots"][content="noindex, nofollow"]'));
        Assert::assertCount(1, $crawler->filter('meta[name="referrer"][content="no-referrer"]'));
    }
}
