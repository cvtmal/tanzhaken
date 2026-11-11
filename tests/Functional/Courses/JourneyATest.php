<?php

declare(strict_types=1);

namespace App\Tests\Functional\Courses;

use App\Courses\Form\ConfirmActionType;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Journey A up to the enrolments (PRD): a teacher creates "Salsa Beginners" on Mondays at 19:00,
 * sees the lessons and enrols two existing students.
 */
#[ResetDatabase]
final class JourneyATest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CoursePages;
    use LogsInTeacher;

    public function test_create_a_course_and_enrol_two_students(): void
    {
        self::mockTime('2026-03-01 10:00:00');
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        $alex = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        $sam = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Sam', 'lastName' => 'Keller']);

        $crawler = $client->request('GET', '/courses');
        self::assertSelectorTextContains('.empty-state', 'No courses yet');
        $crawler = $client->click($crawler->filter('.empty-state a')->link());
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Create course')->form(), [
            'course[name]' => 'Salsa Beginners',
            'course[weekday]' => '1',
            'course[localStartTime]' => '19:00',
            'course[durationMinutes]' => '60',
            'course[location]' => 'Studio Nord',
            'course[startsOn]' => '2026-03-02',
        ]);
        self::assertResponseStatusCodeSame(303);
        $path = (string) $client->getResponse()->headers->get('Location');
        $id = Uuid::fromString(substr($path, \strlen('/courses/')));

        $crawler = self::followToFlash($client, $path, 'success', 'Course “Salsa Beginners” created.');
        self::assertSelectorTextContains('h1', 'Salsa Beginners');
        self::assertSelectorTextContains('.lesson-all__toggle', 'All lessons (26)');
        self::assertSame('Mon 2 Mar', trim($crawler->filter('.lesson-row__date')->first()->text()));
        self::assertSelectorTextContains('.lesson-cards', 'Mon 2 Mar');

        $name = ConfirmActionType::formName('enroll', $id);
        foreach ([$alex, $sam] as $student) {
            $client->submit($crawler->selectButton('Add to course')->form(), [$name.'[student]' => $student->getId()->toRfc4122()]);
            $crawler = self::followToFlash($client, $path, 'success', $student->getFirstName().' '.$student->getLastName().' enrolled.');
        }

        self::assertSame(
            ['Sam Keller', 'Alex Meier'],
            $crawler->filter('tr.roster-row .roster-row__name')->each(static fn (Crawler $row): string => trim($row->text())),
        );
        // Both are enrolled, so the picker is replaced by the "create a student" link.
        self::assertSelectorTextContains('#enroll', 'All your students are already in this course.');

        $client->request('GET', '/courses');
        self::assertSelectorTextContains('.list-row', 'Salsa Beginners');
        self::assertSelectorTextContains('.list-row', '2 students · Studio Nord');
    }
}
