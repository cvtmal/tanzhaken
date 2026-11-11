<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\Courses\CoursePages;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Journey E (PRD §11): Alex buys a 10-lesson pass, comes ten times (balance 0, "Renewal needed"),
 * comes an eleventh time anyway (accepted, one lesson outstanding) and renews: the new pass
 * covers the outstanding lesson and leaves 9.
 */
#[ResetDatabase]
final class JourneyETest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;

    public function test_ten_lessons_use_the_pass_the_eleventh_is_owed_and_a_renewal_covers_it(): void
    {
        self::mockTime('2026-11-20 12:00:00');
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        $course = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Salsa Beginners']);
        $alex = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        $enrollment = EnrollmentFactory::createOne(['course' => $course, 'student' => $alex]);
        CreditGrantFactory::createOne(['enrollment' => $enrollment]);
        $lessons = [];
        for ($week = 0; $week < 11; ++$week) {
            $lessons[] = self::lessonAt($course, \sprintf('2026-09-01 18:00:00 +%d weeks', $week));
        }

        for ($i = 0; $i < 10; ++$i) {
            $crawler = $this->markPresent($client, $lessons[$i], $alex);
            self::assertSame((string) (9 - $i), $this->chip($crawler, $alex));
        }
        self::assertStringContainsString('Renewal needed', self::row($crawler, $alex)->filter('.register-row__balance')->text());

        $crawler = $client->request('GET', self::lessonPath($lessons[10]));
        self::assertSame('0', $this->chip($crawler, $alex));
        self::assertStringContainsString('Present uses 1 lesson. Alex would then owe 1.', self::row($crawler, $alex)->filter('.register-row__cost')->text());

        $crawler = $this->markPresent($client, $lessons[10], $alex);
        self::assertSame('present', self::storedStatus($client, $lessons[10], $alex));
        self::assertSame('−1', $this->chip($crawler, $alex));
        self::assertStringContainsString('1 lesson outstanding', self::row($crawler, $alex)->filter('.register-row__balance')->text());

        CreditGrantFactory::createOne(['enrollment' => $enrollment]);
        $crawler = $client->request('GET', self::lessonPath($lessons[10]));
        self::assertSame('9', $this->chip($crawler, $alex));
        self::assertStringNotContainsString('outstanding', self::row($crawler, $alex)->filter('.register-row__balance')->text());
    }

    /**
     * Marks the student present through the lesson page and returns the page after the redirect.
     */
    private function markPresent(KernelBrowser $client, LessonSession $lesson, Student $student): Crawler
    {
        $crawler = $client->request('GET', self::lessonPath($lesson));
        $client->submit(self::rowForm($crawler, $student, 'Present'));

        return self::followToFlash($client, self::rowTarget($lesson, $student), 'success', 'Alex M. marked present');
    }

    private function chip(Crawler $crawler, Student $student): string
    {
        return self::row($crawler, $student)->filter('.register-row__balance .balance__chip')->text();
    }
}
