<?php

declare(strict_types=1);

namespace App\Tests\Functional\Credits;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Enrollment;
use App\Credits\Entity\CreditGrant;
use App\Tests\Factory\CreditGrantFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Voiding and correcting a credit entry from the student page (CRD-5, S9 "void plus a new grant",
 * PD2, PD9): the balance follows, the voided entry stays struck through, each change writes its
 * audit rows; a voided entry has no void or correct page; a POST without a valid token changes
 * nothing.
 */
#[ResetDatabase]
final class VoidCorrectTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use CreditPages;

    private const NOW = '2026-03-04 12:00:00';

    private KernelBrowser $client;
    private Teacher $teacher;
    private Enrollment $alex;
    private CreditGrant $grant;

    protected function setUp(): void
    {
        self::mockTime(self::NOW);
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
        $this->alex = self::enrolled($this->teacher, 'Alex', 'Meier');
        $this->grant = CreditGrantFactory::createOne(['enrollment' => $this->alex, 'quantity' => 10]);
    }

    public function test_void_with_a_reason_takes_the_lessons_back(): void
    {
        $crawler = $this->client->request('GET', self::studentPath($this->alex));
        self::assertSame(10, self::balanceOf($this->client, $this->teacher, $this->alex));
        $crawler = $this->client->click($crawler->filter('a[aria-label="Void the entry of Mar 4, 2026"]')->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The balance drops by 10 lessons, to 0.', (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->selectButton('Void entry')->form(), ['void[reason]' => 'Entered twice']);
        $crawler = self::followToToast($this->client, self::studentPath($this->alex), 'Entry of 10 lessons for Alex Meier (Salsa Beginners) voided.');

        self::assertSame(0, self::balanceOf($this->client, $this->teacher, $this->alex));
        self::assertSame(self::NOW, self::db($this->client)->fetchOne('SELECT voided_at FROM credit_grant WHERE id = ?', [$this->grant->getId()->toBinary()]));
        $details = self::db($this->client)->fetchOne("SELECT details_json FROM audit_event WHERE action = 'credit_grant.voided'");
        self::assertIsString($details);
        self::assertSame('Entered twice', json_decode($details, true, flags: \JSON_THROW_ON_ERROR)['reason'] ?? null);
        self::assertSame(1, self::auditCount($this->client, 'credit_grant.voided'));

        $entry = $crawler->filter('.credit-history__entry');
        self::assertCount(1, $entry);
        self::assertStringContainsString('credit-history__entry--voided', (string) $entry->attr('class'));
        self::assertSame('Voided Mar 4, 2026', trim($entry->filter('.credit-history__voided')->text()));
        self::assertCount(0, $entry->filter('.credit-history__actions a'));
    }

    public function test_correct_ten_to_eight_voids_the_entry_and_adds_the_new_one(): void
    {
        $crawler = $this->client->request('GET', self::studentPath($this->alex));
        $crawler = $this->client->click($crawler->filter('a[aria-label="Correct the entry of Mar 4, 2026"]')->link());
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.credit-form')->form();
        self::assertSame('10', $form->getValues()['correct[amount]']);
        self::assertSame('2026-03-04', $form->getValues()['correct[date]']);

        $this->client->submit($form, ['correct[amount]' => 'other', 'correct[otherAmount]' => '8', 'correct[reason]' => 'Wrong pass']);
        $crawler = self::followToToast($this->client, self::studentPath($this->alex), 'Entry for Alex Meier (Salsa Beginners) corrected to 8 lessons.');

        self::assertSame(8, self::balanceOf($this->client, $this->teacher, $this->alex));
        self::assertNotNull(self::db($this->client)->fetchOne('SELECT voided_at FROM credit_grant WHERE id = ?', [$this->grant->getId()->toBinary()]));
        self::assertSame(2, self::countRows($this->client, 'credit_grant'));
        self::assertSame(8, (int) self::db($this->client)->fetchOne('SELECT quantity FROM credit_grant WHERE voided_at IS NULL'));
        self::assertSame(1, self::auditCount($this->client, 'credit_grant.voided'));
        self::assertSame(1, self::auditCount($this->client, 'credit_grant.created'));
        self::assertSame(2, self::countRows($this->client, 'audit_event'));
        self::assertCount(2, $crawler->filter('.credit-history__entry'));
        self::assertCount(1, $crawler->filter('.credit-history__entry--voided'));
    }

    public function test_a_voided_entry_has_no_void_or_correct_page(): void
    {
        $voided = CreditGrantFactory::new(['enrollment' => $this->alex, 'quantity' => 5])->voided()->create();
        $studentId = $this->alex->getStudent()->getId();

        foreach (['void', 'correct'] as $action) {
            foreach (['GET', 'POST'] as $method) {
                $this->client->request($method, self::grantActionPath($action, $studentId, $voided->getId()));
                self::assertResponseStatusCodeSame(404, $method.' '.$action);
            }
        }
        self::assertSame(0, self::countRows($this->client, 'audit_event'));
    }

    public function test_a_post_without_a_valid_token_changes_nothing(): void
    {
        $studentId = $this->alex->getStudent()->getId();
        $posts = [
            'void' => ['reason' => 'Entered twice'],
            'correct' => ['amount' => '5', 'date' => '2026-03-04', 'from' => 'student'],
        ];

        foreach ($posts as $action => $fields) {
            foreach ([$fields, ['_token' => 'invalid'] + $fields] as $data) {
                $this->client->request('POST', self::grantActionPath($action, $studentId, $this->grant->getId()), [$action => $data]);
                self::assertResponseStatusCodeSame(422, $action);
            }
        }

        self::assertSame(1, self::countRows($this->client, 'credit_grant'));
        self::assertNull(self::db($this->client)->fetchOne('SELECT voided_at FROM credit_grant'));
        self::assertSame(0, self::countRows($this->client, 'audit_event'));
        self::assertSame(10, self::balanceOf($this->client, $this->teacher, $this->alex));
    }
}
