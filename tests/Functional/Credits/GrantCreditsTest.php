<?php

declare(strict_types=1);

namespace App\Tests\Functional\Credits;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Enrollment;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Adding credits (CRD-1, E2, PD4–PD6): from the roster, the student page and the dashboard, each
 * back to its origin with the new balance; the effective date, the amount and the note are
 * validated; the return target is never a URL from the request. One audit row per grant.
 */
#[ResetDatabase]
final class GrantCreditsTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CreditPages;

    /** UTC; 13:00 on Wednesday 4 March 2026 in Zurich (CET). */
    private const NOW = '2026-03-04 12:00:00';

    private KernelBrowser $client;
    private Teacher $teacher;
    private Enrollment $alex;

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
        $this->alex = self::enrolled($this->teacher, 'Alex', 'Meier');
    }

    public function test_grant_ten_from_the_roster_returns_to_the_course_page(): void
    {
        $crawler = $this->client->request('GET', self::coursePath($this->alex));
        $add = self::rosterRow($crawler, 'Alex Meier')->filter('a.roster-balance__add');
        self::assertSame('Add 10 lessons for Alex Meier', $add->attr('aria-label'));

        $crawler = $this->client->click($add->link());
        self::assertResponseIsSuccessful();
        self::assertSame('10', trim($crawler->filter('[data-credit-after]')->text()));
        $form = $crawler->filter('form.credit-form')->form();
        self::assertSame('10', $form->getValues()['grant[amount]']);
        self::assertSame('course', $form->getValues()['grant[from]']);
        self::assertSame('2026-03-04', $form->getValues()['grant[date]']);

        $this->client->submit($crawler->selectButton('Add 10 lessons')->form());
        $crawler = self::followToToast($this->client, self::coursePath($this->alex), '10 lessons added for Alex Meier (Salsa Beginners). Balance: 10.');

        $cell = self::rosterRow($crawler, 'Alex Meier')->filter('td.roster-balance[data-label="Lessons left"]');
        self::assertSame('10', trim($cell->filter('.balance .balance__chip')->text()));
        self::assertCount(1, $cell->filter('.balance--neutral'));
        self::assertCount(0, $cell->filter('a.roster-balance__add'));
        self::assertSame(10, self::balanceOf($this->client, $this->teacher, $this->alex));
        // Today: the ledger stamps the grant with now.
        self::assertSame(self::NOW, self::db($this->client)->fetchOne('SELECT granted_at FROM credit_grant'));
        self::assertSame(1, self::auditCount($this->client, 'credit_grant.created'));
    }

    public function test_grant_five_and_other_seven_from_the_student_page(): void
    {
        $crawler = $this->client->request('GET', self::studentPath($this->alex));
        $crawler = $this->client->click($crawler->filter('section.credits-student')->selectLink('Add credits')->link());
        self::assertResponseIsSuccessful();
        self::assertSame('student', $crawler->filter('form.credit-form')->form()->getValues()['grant[from]']);

        $this->client->submit($crawler->filter('form.credit-form')->form(), ['grant[amount]' => '5']);
        $crawler = self::followToToast($this->client, self::studentPath($this->alex), '5 lessons added for Alex Meier (Salsa Beginners). Balance: 5.');
        self::assertSame(1, self::auditCount($this->client, 'credit_grant.created'));

        $crawler = $this->client->click($crawler->filter('section.credits-student')->selectLink('Add credits')->link());
        $this->client->submit($crawler->filter('form.credit-form')->form(), ['grant[amount]' => 'other', 'grant[otherAmount]' => '7']);
        $crawler = self::followToToast($this->client, self::studentPath($this->alex), '7 lessons added for Alex Meier (Salsa Beginners). Balance: 12.');

        self::assertSame(12, self::balanceOf($this->client, $this->teacher, $this->alex));
        self::assertSame(2, self::auditCount($this->client, 'credit_grant.created'));
        self::assertSame(
            ['+7 lessons', '+5 lessons'],
            $crawler->filter('.credit-history__entry .credit-history__quantity')->each(static fn (Crawler $q): string => trim($q->text())),
        );
        self::assertSame('12', trim($crawler->filter('section.credits-student .balance__chip')->text()));
    }

    public function test_an_earlier_date_is_stored_at_midnight_in_the_teachers_zone(): void
    {
        $crawler = $this->client->request('GET', self::grantPath($this->alex, 'student'));
        $this->client->submit($crawler->filter('form.credit-form')->form(), ['grant[date]' => '2026-03-01', 'grant[note]' => 'Paid in cash']);
        $crawler = self::followToToast($this->client, self::studentPath($this->alex), 'Balance: 10.');

        // 00:00 on 1 March in Zurich (UTC+1) is 23:00 UTC the day before.
        self::assertSame('2026-02-28 23:00:00', self::db($this->client)->fetchOne('SELECT granted_at FROM credit_grant'));
        $entry = $crawler->filter('.credit-history__entry');
        self::assertStringContainsString('Mar 1, 2026', $entry->filter('.credit-history__date')->text());
        self::assertStringContainsString('Paid in cash', $entry->filter('.credit-history__note')->text());
    }

    public function test_a_future_date_is_refused_with_422(): void
    {
        $crawler = $this->client->request('GET', self::grantPath($this->alex));
        $this->client->submit($crawler->filter('form.credit-form')->form(), ['grant[date]' => '2026-03-05']);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The date cannot be in the future.', (string) $this->client->getResponse()->getContent());
        $this->assertNothingGranted();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOtherAmounts(): iterable
    {
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'above the cap' => ['101'];
    }

    #[DataProvider('invalidOtherAmounts')]
    public function test_an_other_amount_outside_1_to_100_is_refused_with_422(string $amount): void
    {
        $crawler = $this->client->request('GET', self::grantPath($this->alex));
        $this->client->submit($crawler->filter('form.credit-form')->form(), ['grant[amount]' => 'other', 'grant[otherAmount]' => $amount]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Enter a number of lessons between 1 and 100.', (string) $this->client->getResponse()->getContent());
        $this->assertNothingGranted();
    }

    public function test_a_note_longer_than_255_characters_is_refused_with_422(): void
    {
        $crawler = $this->client->request('GET', self::grantPath($this->alex));
        $this->client->submit($crawler->filter('form.credit-form')->form(), ['grant[note]' => str_repeat('a', 256)]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This note is too long.', (string) $this->client->getResponse()->getContent());
        $this->assertNothingGranted();
    }

    public function test_the_dashboard_plus_ten_returns_to_the_dashboard(): void
    {
        $crawler = $this->client->request('GET', '/dashboard');
        $add = $crawler->filter('.dashboard__renewals a.renewal-add');
        self::assertCount(1, $add);
        self::assertSame('Add 10 lessons for Alex Meier', $add->attr('aria-label'));
        self::assertStringEndsWith('?from=dashboard', (string) $add->attr('href'));

        $crawler = $this->client->click($add->link());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->selectButton('Add 10 lessons')->form());

        $crawler = self::followToToast($this->client, '/dashboard', '10 lessons added for Alex Meier (Salsa Beginners). Balance: 10.');
        self::assertCount(0, $crawler->filter('.dashboard__renewals a.renewal-add'));
        self::assertSame(1, self::auditCount($this->client, 'credit_grant.created'));
    }

    public function test_a_foreign_return_target_falls_back_to_the_course_page(): void
    {
        $crawler = $this->client->request('GET', self::grantPath($this->alex, 'https://evil.example'));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.credit-form')->form();
        self::assertSame('course', $form->getValues()['grant[from]']);

        // A tampered hidden field is not followed either.
        $this->client->submit($form, ['grant[from]' => 'https://evil.example']);
        self::followToToast($this->client, self::coursePath($this->alex), 'Balance: 10.');
        self::assertSame(1, self::auditCount($this->client, 'credit_grant.created'));
    }

    private function assertNothingGranted(): void
    {
        self::assertSame(0, self::countRows($this->client, 'credit_grant'));
        self::assertSame(0, self::auditCount($this->client, 'credit_grant.created'));
    }
}
