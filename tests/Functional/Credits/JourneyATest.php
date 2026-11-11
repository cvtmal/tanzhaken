<?php

declare(strict_types=1);

namespace App\Tests\Functional\Credits;

use App\Courses\Form\ConfirmActionType;
use App\Tests\Factory\StudentFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Journey A up to the first pass (PRD, cycle #12): a teacher creates "Salsa Beginners" on Mondays
 * at 19:00, enrols Alex and Sam, and adds Alex's 10-lesson pass with the roster's "+10" in two
 * taps; the roster and Alex's student page show the new balance.
 */
#[ResetDatabase]
final class JourneyATest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CreditPages;

    public function test_create_a_course_enrol_two_students_and_add_alexs_pass(): void
    {
        self::mockTime('2026-03-01 10:00:00');
        $client = self::createClient();
        $teacher = $this->logInTeacher($client);
        $alex = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Alex', 'lastName' => 'Meier']);
        $sam = StudentFactory::createOne(['teacher' => $teacher, 'firstName' => 'Sam', 'lastName' => 'Keller']);

        $crawler = $client->request('GET', '/courses/new');
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
        $crawler = self::followToToast($client, $path, 'Course “Salsa Beginners” created.');

        $name = ConfirmActionType::formName('enroll', $id);
        foreach ([$alex, $sam] as $student) {
            $client->submit($crawler->selectButton('Add to course')->form(), [$name.'[student]' => $student->getId()->toRfc4122()]);
            $crawler = self::followToToast($client, $path, $student->getFirstName().' '.$student->getLastName().' enrolled.');
        }

        // Tap 1: "+10" on Alex's row; tap 2: the preset "Add 10 lessons".
        $crawler = $client->click(self::rosterRow($crawler, 'Alex Meier')->filter('a.roster-balance__add')->link());
        self::assertResponseIsSuccessful();
        $client->submit($crawler->selectButton('Add 10 lessons')->form());
        $crawler = self::followToToast($client, $path, '10 lessons added for Alex Meier (Salsa Beginners). Balance: 10.');

        $alexCell = self::rosterRow($crawler, 'Alex Meier')->filter('td.roster-balance');
        self::assertSame('10', trim($alexCell->filter('.balance__chip')->text()));
        self::assertCount(0, $alexCell->filter('a.roster-balance__add'));
        $samCell = self::rosterRow($crawler, 'Sam Keller')->filter('td.roster-balance');
        self::assertSame('Renewal needed', trim($samCell->filter('.balance__label')->text()));
        self::assertCount(1, $samCell->filter('a.roster-balance__add'));

        $crawler = $client->click($crawler->selectLink('Alex Meier')->link());
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('section.credits-student');
        self::assertSame('10', trim($section->filter('article.card .balance__chip')->text()));
        self::assertSame(
            ['+10 lessons'],
            $section->filter('.credit-history__entry .credit-history__quantity')->each(static fn (Crawler $q): string => trim($q->text())),
        );
    }
}
