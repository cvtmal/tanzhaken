<?php

declare(strict_types=1);

namespace App\Tests\Functional\Credits;

use App\Account\Entity\Teacher;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The credit slots (REG-5, E1/E3, D-036, PD7): the roster's "Lessons left" cell with its S9 badge
 * and the "+10" only at a balance of 2 or less on an open course, the course card's "n to renew",
 * the student page's credits section, and a query count on the course and student pages that does
 * not grow with the enrollments (PERF-1).
 */
#[ResetDatabase]
final class CreditBadgesTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CreditPages;

    private KernelBrowser $client;
    private Teacher $teacher;

    protected function setUp(): void
    {
        self::mockTime('2026-03-04 12:00:00');
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
    }

    public function test_roster_shows_the_balance_state_and_plus_ten_only_at_two_or_less(): void
    {
        $alex = self::enrolled($this->teacher, 'Alex', 'Meier', 0);
        $course = $alex->getCourse();
        self::enrolled($this->teacher, 'Sam', 'Keller', -2, $course);
        self::enrolled($this->teacher, 'Kim', 'Graf', 2, $course);
        self::enrolled($this->teacher, 'Noa', 'Frei', 3, $course);
        EnrollmentFactory::new([
            'course' => $course,
            'student' => StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Jo', 'lastName' => 'Left']),
        ])->left()->create();

        $crawler = $this->client->request('GET', self::coursePath($alex));
        self::assertResponseIsSuccessful();
        self::assertContains('Lessons left', $crawler->filter('th')->each(static fn (Crawler $th): string => trim($th->text())));

        self::assertRosterCell($crawler, 'Alex Meier', 'danger', '0', 'Renewal needed', true);
        self::assertRosterCell($crawler, 'Sam Keller', 'danger', '−2', '2 lessons outstanding', true);
        self::assertRosterCell($crawler, 'Kim Graf', 'low', '2', 'Low', true);
        self::assertRosterCell($crawler, 'Noa Frei', 'neutral', '3', null, false);
        self::assertCount(1, self::rosterRow($crawler, 'Noa Frei')->filter('.roster-balance__bar'));
        self::assertCount(4, $crawler->filter('tr.roster-row'));
        self::assertStringNotContainsString('Jo Left', $crawler->filter('table')->text());
    }

    public function test_an_archived_course_offers_no_plus_ten_and_no_grant_page(): void
    {
        $course = CourseFactory::new(['teacher' => $this->teacher, 'name' => 'Old Salsa'])->archived()->create();
        $alex = self::enrolled($this->teacher, 'Alex', 'Meier', 0, $course);

        $crawler = $this->client->request('GET', self::coursePath($alex));
        self::assertResponseIsSuccessful();
        $cell = self::rosterRow($crawler, 'Alex Meier')->filter('td.roster-balance');
        self::assertCount(1, $cell->filter('.balance--danger'));
        self::assertCount(0, $cell->filter('a.roster-balance__add'));

        $this->client->request('GET', self::grantPath($alex));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_course_card_counts_the_passes_to_renew(): void
    {
        $alex = self::enrolled($this->teacher, 'Alex', 'Meier', 0, courseName: 'Salsa Beginners');
        self::enrolled($this->teacher, 'Sam', 'Keller', 4, $alex->getCourse());
        self::enrolled($this->teacher, 'Kim', 'Graf', 5, courseName: 'Tango Basics');

        $crawler = $this->client->request('GET', '/courses');
        self::assertResponseIsSuccessful();

        $salsa = $crawler->filter('.list-row')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Salsa Beginners'));
        self::assertSame('1 to renew', trim($salsa->filter('.badge.badge--danger.badge--sm')->text()));
        $tango = $crawler->filter('.list-row')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Tango Basics'));
        self::assertCount(1, $tango);
        self::assertCount(0, $tango->filter('.badge--danger'));
    }

    public function test_student_page_lists_every_enrollment_with_balance_and_history(): void
    {
        $salsa = self::enrolled($this->teacher, 'Alex', 'Meier', 0, courseName: 'Salsa Beginners');
        CreditGrantFactory::createOne(['enrollment' => $salsa, 'quantity' => 10, 'note' => 'Paid in cash']);
        $tango = EnrollmentFactory::createOne([
            'course' => CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Tango Basics']),
            'student' => $salsa->getStudent(),
        ]);

        $crawler = $this->client->request('GET', self::studentPath($salsa));
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('section.credits-student');
        self::assertSame('Credits', trim($section->filter('h2')->text()));

        $cards = $section->filter('article.card');
        self::assertCount(2, $cards);
        self::assertSame(['Salsa Beginners', 'Tango Basics'], $cards->filter('.credit-account__course')->each(static fn (Crawler $c): string => trim($c->text())));

        $first = $cards->eq(0);
        self::assertSame('10', trim($first->filter('.balance__chip')->text()));
        self::assertCount(1, $first->filter('.credit-history__entry'));
        self::assertStringContainsString('+10 lessons', $first->filter('.credit-history__entry')->text());
        self::assertStringContainsString('Paid in cash', $first->filter('.credit-history__entry')->text());

        $second = $cards->eq(1);
        self::assertCount(1, $second->filter('.balance--danger'));
        self::assertStringContainsString('Renewal needed', $second->text());
        self::assertStringContainsString('No credits yet', $second->text());
        self::assertSame(
            self::grantPath($tango, 'student'),
            $second->selectLink('Add credits')->attr('href'),
        );
    }

    public function test_course_and_student_page_query_counts_do_not_grow_with_the_enrollments(): void
    {
        $this->client->disableReboot();
        [$smallTeacher, $smallCourse, $smallStudent] = self::seed(3);
        [$largeTeacher, $largeCourse, $largeStudent] = self::seed(15);

        foreach (['course' => [$smallCourse, $largeCourse], 'student' => [$smallStudent, $largeStudent]] as $page => [$small, $large]) {
            $smallCount = $this->queryCount($smallTeacher, $small);
            $largeCount = $this->queryCount($largeTeacher, $large);
            self::assertSame($smallCount, $largeCount, \sprintf('%s page: %d queries with 3 enrollments, %d with 15', $page, $smallCount, $largeCount));
        }
    }

    /**
     * A teacher with one course of `$size` enrolled students (balances 0, 1, 2, 3, 0, …: most
     * rows show the "+10") and one student enrolled in `$size` courses (a grant each).
     *
     * @return array{Teacher, string, string} teacher, course page path, student page path
     */
    private static function seed(int $size): array
    {
        $teacher = TeacherFactory::createOne();
        $course = CourseFactory::createOne(['teacher' => $teacher]);
        $student = StudentFactory::createOne(['teacher' => $teacher]);
        for ($i = 0; $i < $size; ++$i) {
            self::enrolled($teacher, 'Alex', \sprintf('Student %02d', $i), $i % 4, $course);
            $other = EnrollmentFactory::createOne(['course' => CourseFactory::createOne(['teacher' => $teacher]), 'student' => $student]);
            CreditGrantFactory::createOne(['enrollment' => $other, 'quantity' => 1 + $i % 3]);
        }

        return [$teacher, '/courses/'.$course->getId()->toRfc4122(), '/students/'.$student->getId()->toRfc4122()];
    }

    private function queryCount(Teacher $teacher, string $path): int
    {
        $this->logInTeacher($this->client, $teacher);
        $container = $this->client->getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear(); // as in a fresh request: the user is reloaded from the database
        $holder = $container->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $holder->reset();

        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return \count($holder->getData()['default'] ?? []);
    }

    private static function assertRosterCell(Crawler $crawler, string $name, string $state, string $chip, ?string $label, bool $plusTen): void
    {
        $cell = self::rosterRow($crawler, $name)->filter('td.roster-balance[data-label="Lessons left"]');
        self::assertCount(1, $cell, $name);
        self::assertCount(1, $cell->filter('.balance.balance--'.$state), $name);
        self::assertSame($chip, trim($cell->filter('.balance__chip')->text()), $name);
        if (null === $label) {
            self::assertCount(0, $cell->filter('.balance__label'), $name);
        } else {
            self::assertSame($label, trim($cell->filter('.balance__label')->text()), $name);
        }
        $add = $cell->filter('a.roster-balance__add');
        self::assertCount($plusTen ? 1 : 0, $add, $name);
        if ($plusTen) {
            self::assertSame('+10', trim($add->text()), $name);
            self::assertSame('Add 10 lessons for '.$name, $add->attr('aria-label'), $name);
        }
    }
}
