<?php

declare(strict_types=1);

namespace App\Tests\Functional\Attendance;

use App\Courses\Entity\Course;
use App\Students\Entity\Student;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\Courses\CoursePages;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Journey F (PRD): Alex is marked present by mistake; the teacher changes it to absent on the
 * lesson page with a reason. The lesson comes back to Alex's balance and the change is audited.
 */
#[ResetDatabase]
final class JourneyFTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LessonPages;
    use LogsInTeacher;

    public function test_a_wrong_present_is_changed_to_absent_with_a_reason(): void
    {
        self::mockTime('2026-11-10 19:30:00');
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        $course = CourseFactory::createOne(['teacher' => $teacher, 'name' => 'Salsa Beginners']);
        $lesson = self::lessonAt($course, '2026-11-10 18:00:00');
        $alex = $this->enrolled($course, 'Alex', 'Meier');
        $sam = $this->enrolled($course, 'Sam', 'Keller');

        $crawler = $client->request('GET', self::lessonPath($lesson));
        self::assertSame('10', $this->chip($crawler, $alex));
        foreach ([$sam, $alex] as $student) {
            $client->submit(self::rowForm($crawler, $student, 'Present'));
            $crawler = self::followToFlash($client, self::rowTarget($lesson, $student), 'success', $student->getFirstName().' '.$student->getLastName()[0].'. marked present');
        }
        self::assertSelectorTextSame('#lesson-present-count', '2 of 2 present');
        self::assertSame('9', $this->chip($crawler, $alex));

        $client->submit(self::rowForm($crawler, $alex, 'Absent'), [self::reasonField($alex) => 'Marked the wrong Alex']);

        $crawler = self::followToFlash($client, self::rowTarget($lesson, $alex), 'success', 'Alex M. marked absent');
        self::assertSelectorTextSame('#lesson-present-count', '1 of 2 present');
        self::assertSame('10', $this->chip($crawler, $alex));
        self::assertStringStartsWith('Absent · 8:30 PM · by you', self::plain(self::row($crawler, $alex)->filter('.register-row__meta')->text()));
        self::assertSame('absent', self::storedStatus($client, $lesson, $alex));

        $change = array_values(array_filter(
            self::auditDetails($client, 'attendance.marked'),
            static fn (array $details): bool => $alex->getId()->toRfc4122() === $details['student_id'] && 'absent' === $details['status'],
        ));
        self::assertCount(1, $change);
        self::assertSame('present', $change[0]['previous_status']);
        self::assertSame('teacher', $change[0]['previous_source']);
        self::assertSame('Marked the wrong Alex', $change[0]['reason']);
        self::assertSame($lesson->getId()->toRfc4122(), $change[0]['lesson_id']);
    }

    private function enrolled(Course $course, string $firstName, string $lastName): Student
    {
        $student = StudentFactory::createOne(['teacher' => $course->getTeacher(), 'firstName' => $firstName, 'lastName' => $lastName]);
        CreditGrantFactory::createOne(['enrollment' => EnrollmentFactory::createOne(['course' => $course, 'student' => $student])]);

        return $student;
    }

    private function chip(Crawler $crawler, Student $student): string
    {
        return self::row($crawler, $student)->filter('.register-row__balance .balance__chip')->text();
    }
}
