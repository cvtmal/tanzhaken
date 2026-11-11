<?php

declare(strict_types=1);

namespace App\Tests\Functional\Students;

use App\Account\Entity\Teacher;
use App\Students\Entity\Student;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\LogsInTeacher;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The directory writes through real routes (#9, STU-1): create and edit through the form,
 * archive and restore as CSRF-protected POSTs (S4), each with its audit row (S2).
 */
#[ResetDatabase]
final class StudentWritesTest extends WebTestCase
{
    use LogsInTeacher;

    private KernelBrowser $client;
    private Teacher $teacher;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
    }

    public function test_create_redirects_to_the_student_page_with_a_toast_and_audits(): void
    {
        $this->client->request('GET', '/students/new');

        $this->client->submitForm('Add student', [
            'student[firstName]' => 'Anna',
            'student[lastName]' => 'Meier',
            'student[email]' => 'Anna@Example.com',
            'student[notes]' => 'Knee',
        ]);

        self::assertResponseStatusCodeSame(303);
        $row = $this->connection()->fetchAssociative('SELECT * FROM student');
        self::assertIsArray($row);
        $id = Uuid::fromBinary((string) $row['id']);
        self::assertTrue(Uuid::fromBinary((string) $row['teacher_id'])->equals($this->teacher->getId()));
        self::assertSame('Anna', $row['first_name']);
        self::assertSame('anna@example.com', $row['email']);
        self::assertResponseRedirects('/students/'.$id);

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.toast__message', 'Anna Meier was added.');
        self::assertSame(['student.created'], $this->auditActions());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'empty first name' => [['student[firstName]' => ''], 'Enter a first name.'];
        yield 'bad email' => [['student[email]' => 'anna@'], 'Enter a full email address.'];
        yield 'last name too long' => [['student[lastName]' => str_repeat('x', 81)], 'This last name is too long.'];
    }

    /**
     * @param array<string, string> $override
     */
    #[DataProvider('invalidInput')]
    public function test_invalid_create_answers_422_with_our_message_and_writes_nothing(array $override, string $message): void
    {
        $this->client->request('GET', '/students/new');

        $this->client->submitForm('Add student', [
            ...['student[firstName]' => 'Anna', 'student[lastName]' => 'Meier', 'student[email]' => ''],
            ...$override,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', $message);
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM student'));
        self::assertSame([], $this->auditActions());
    }

    public function test_edit_changes_values_lower_cases_the_email_and_audits(): void
    {
        $student = $this->student(['firstName' => 'Anna', 'lastName' => 'Meier']);
        $this->client->request('GET', '/students/'.$student->getId().'/edit');

        $this->client->submitForm('Save changes', [
            'student[lastName]' => 'Keller',
            'student[email]' => 'ANNA@Example.COM',
        ]);

        self::assertResponseRedirects('/students/'.$student->getId(), 303);
        $row = $this->row($student);
        self::assertSame('Anna', $row['first_name']);
        self::assertSame('Keller', $row['last_name']);
        self::assertSame('anna@example.com', $row['email']);
        self::assertSame(['student.updated'], $this->auditActions());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast__message', 'Changes saved.');
        self::assertSelectorTextContains('h1', 'Anna Keller');
    }

    public function test_archive_and_restore_move_the_student_between_the_lists(): void
    {
        $student = $this->student(['firstName' => 'Anna', 'lastName' => 'Meier']);
        $page = '/students/'.$student->getId();

        $this->client->request('GET', $page);
        $this->client->submitForm('Archive');

        self::assertResponseRedirects($page, 303);
        self::assertNotNull($this->row($student)['archived_at']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast__message', 'Anna Meier was archived.');
        self::assertSame([], $this->indexTitles('/students'));
        self::assertSame(['Anna Meier'], $this->indexTitles('/students?archived=1'));

        $this->client->request('GET', $page);
        $this->client->submitForm('Restore');

        self::assertResponseRedirects($page, 303);
        self::assertNull($this->row($student)['archived_at']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.toast__message', 'Anna Meier was restored.');
        self::assertSame(['Anna Meier'], $this->indexTitles('/students'));
        self::assertSame([], $this->indexTitles('/students?archived=1'));
        self::assertSame(['student.archived', 'student.restored'], $this->auditActions());
    }

    public function test_archive_with_a_wrong_token_changes_nothing(): void
    {
        $student = $this->student();

        $this->client->request('POST', '/students/'.$student->getId().'/archive', ['_token' => 'wrong']);

        // Symfony's #[IsCsrfTokenValid] throws InvalidCsrfTokenException, an AuthenticationException,
        // which the firewall answers with its entry point (the login page), not a 4xx.
        self::assertResponseRedirects('/login');
        self::assertNull($this->row($student)['archived_at']);
        self::assertSame([], $this->auditActions());
    }

    public function test_archiving_an_archived_student_warns_and_writes_no_second_audit_row(): void
    {
        $student = $this->student(['firstName' => 'Anna', 'lastName' => 'Meier']);
        $page = '/students/'.$student->getId();
        $crawler = $this->client->request('GET', $page);
        $token = (string) $crawler->filter('form[action$="/archive"] input[name="_token"]')->attr('value');
        $this->client->submitForm('Archive');
        self::assertResponseRedirects($page, 303);

        $this->client->request('POST', $page.'/archive', ['_token' => $token]);

        self::assertResponseRedirects($page, 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--warning', 'Anna Meier is already archived.');
        self::assertSame(['student.archived'], $this->auditActions());
    }

    public function test_get_on_the_archive_route_is_not_allowed(): void
    {
        $student = $this->student();

        $this->client->request('GET', '/students/'.$student->getId().'/archive');

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function student(array $attributes = []): Student
    {
        return StudentFactory::createOne(['teacher' => $this->teacher, ...$attributes]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Student $student): array
    {
        $row = $this->connection()->fetchAssociative('SELECT * FROM student WHERE id = ?', [$student->getId()->toBinary()]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function auditActions(): array
    {
        /** @var list<string> $actions */
        $actions = $this->connection()->fetchFirstColumn("SELECT action FROM audit_event WHERE entity_type = 'student' ORDER BY id");

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function indexTitles(string $url): array
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler->filter('.list-row__title')->each(static fn ($node): string => trim($node->text()));
    }

    private function connection(): Connection
    {
        $connection = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
