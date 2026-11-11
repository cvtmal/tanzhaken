<?php

declare(strict_types=1);

namespace App\Tests\Functional\CheckIn;

use App\Courses\Entity\Enrollment;
use App\Courses\Entity\LessonSession;
use App\Tests\Factory\CheckInWindowFactory;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

use function Zenstruck\Foundry\Persistence\save;

/**
 * Journey D (SQR-2–SQR-8, research Decision 4): every refusal answers 404 with the same public
 * message, on GET and POST, and changes neither attendance nor balances.
 */
#[ResetDatabase]
final class PublicCheckInRefusalTest extends WebTestCase
{
    use OpensPublicCheckIn;

    private const UNKNOWN_TOKEN = 'unknown-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->resetCheckInLimits($this->browser);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function windowRefusals(): iterable
    {
        yield 'expired window' => ['expired'];
        yield 'closed window' => ['closed'];
        yield 'rotated token' => ['rotated'];
        yield 'malformed token' => ['malformed'];
        yield 'unknown well-formed token' => ['unknown'];
        yield 'cancelled lesson' => ['cancelled'];
        yield 'archived course' => ['archived'];
    }

    #[DataProvider('windowRefusals')]
    public function test_a_refused_window_answers_the_one_message_on_get_and_post(string $case): void
    {
        [, $lesson, [$anna]] = self::lessonWithStudents([['firstName' => 'Anna', 'lastName' => 'Berg']]);
        $token = self::arrange($case, $lesson);

        $this->browser->request('GET', '/c/'.$token);
        $get = $this->assertRefused();
        self::confirm($this->browser, $anna->getId()->toRfc4122(), $token);
        $post = $this->assertRefused();

        self::assertSame($get, $post, 'GET and POST answer the same body.');
        self::assertSame($this->referenceBody(), $get, 'Every refusal answers the same body.');
        self::assertSame(0, self::attendanceCount());
        self::assertSame(10, self::balanceOf($anna));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function enrollmentRefusals(): iterable
    {
        yield 'enrollment of another course' => ['other-course'];
        yield 'left enrollment' => ['left'];
        yield 'archived student' => ['archived-student'];
        yield 'unknown enrollment id' => ['unknown'];
    }

    #[DataProvider('enrollmentRefusals')]
    public function test_an_enrollment_off_the_roster_answers_the_one_message(string $case): void
    {
        [$teacher, , [$anna, $ben]] = self::openPublicCheckIn();
        $target = match ($case) {
            'other-course' => EnrollmentFactory::createOne(['course' => CourseFactory::createOne(['teacher' => $teacher]), 'student' => $ben->getStudent()])->getId(),
            'left' => self::leave($ben)->getId(),
            'archived-student' => self::archiveStudent($ben)->getId(),
            default => Uuid::v7(),
        };

        self::confirm($this->browser, $target->toRfc4122());
        $body = $this->assertRefused();

        self::assertSame($this->referenceBody(), $body, 'Every refusal answers the same body.');
        self::assertSame(0, self::attendanceCount());
        self::assertSame(10, self::balanceOf($anna));
    }

    /**
     * Builds the case's window(s) for $lesson and returns the token the visitor holds.
     */
    private static function arrange(string $case, LessonSession $lesson): string
    {
        $now = Clock::get()->now();
        switch ($case) {
            case 'expired':
                self::openWindow($lesson, attributes: ['openedAt' => $now->modify('-2 hours'), 'expiresAt' => $now->modify('-1 hour')]);

                return self::TOKEN;
            case 'closed':
                CheckInWindowFactory::new()->closed()->create(['lessonSession' => $lesson, 'tokenHash' => hash('sha256', self::TOKEN)]);

                return self::TOKEN;
            case 'rotated':
                CheckInWindowFactory::new()->closed()->create(['lessonSession' => $lesson, 'tokenHash' => hash('sha256', self::TOKEN), 'openedAt' => $now->modify('-10 minutes')]);
                self::openWindow($lesson, 'rotated-token-ccccccccccccccccccccccccccccc');

                return self::TOKEN;
            case 'malformed':
                self::openWindow($lesson);

                return 'not-a-token';
            case 'unknown':
                self::openWindow($lesson);

                return self::UNKNOWN_TOKEN;
            case 'cancelled':
                self::openWindow($lesson);
                $lesson->cancel($now);
                save($lesson);

                return self::TOKEN;
            case 'archived':
                self::openWindow($lesson);
                $lesson->getCourse()->archive($now);
                save($lesson->getCourse());

                return self::TOKEN;
        }

        throw new \LogicException(\sprintf('Unknown case "%s".', $case));
    }

    private static function leave(Enrollment $enrollment): Enrollment
    {
        $enrollment->leave(Clock::get()->now());

        return save($enrollment);
    }

    private static function archiveStudent(Enrollment $enrollment): Enrollment
    {
        $student = $enrollment->getStudent();
        $student->archive(Clock::get()->now());
        save($student);

        return $enrollment;
    }

    /**
     * Asserts the last response is the public refusal and returns its body.
     */
    private function assertRefused(): string
    {
        self::assertResponseStatusCodeSame(404);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', "This check-in isn't open");
        self::assertSelectorTextSame('.error-page__text', 'Ask your teacher for the current QR code.');
        self::assertSelectorNotExists('form');

        return (string) $this->browser->getResponse()->getContent();
    }

    /**
     * The body of the refusal of an unknown token: what every other refusal must equal.
     */
    private function referenceBody(): string
    {
        $this->browser->request('GET', '/c/'.self::UNKNOWN_TOKEN);
        self::assertResponseStatusCodeSame(404);

        return (string) $this->browser->getResponse()->getContent();
    }
}
