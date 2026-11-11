<?php

declare(strict_types=1);

namespace App\Tests\Functional\Students;

use App\Account\Entity\Teacher;
use App\Students\Entity\Student;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Teacher B never reaches teacher A's student (S4, Journey G): every route answers 404, the
 * POSTs carry B's own valid tokens so the refusal comes from ownership, not from CSRF, and
 * A's student stays out of B's list and search.
 */
#[ResetDatabase]
final class StudentIsolationTest extends WebTestCase
{
    use LogsInTeacher;

    private KernelBrowser $client;
    private Student $foreign;
    private Student $own;
    private Teacher $teacherA;
    private Teacher $teacherB;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->teacherB = $this->logInTeacher($this->client);
        $this->teacherA = TeacherFactory::createOne();
        $this->foreign = StudentFactory::createOne(['teacher' => $this->teacherA, 'firstName' => 'Anna', 'lastName' => 'Meier', 'email' => 'anna@example.com']);
        $this->own = StudentFactory::createOne(['teacher' => $this->teacherB, 'firstName' => 'Bruno', 'lastName' => 'Albrecht']);
    }

    public function test_foreign_student_pages_answer_404(): void
    {
        $this->client->request('GET', $this->url($this->foreign));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->url($this->foreign, '/edit'));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_foreign_student_writes_answer_404_with_valid_tokens(): void
    {
        $before = $this->snapshot($this->foreign);

        $crawler = $this->client->request('GET', $this->url($this->own, '/edit'));
        $formToken = (string) $crawler->filter('input[name="student[_token]"]')->attr('value');
        self::assertNotSame('', $formToken);
        $this->client->request('POST', $this->url($this->foreign, '/edit'), ['student' => [
            'firstName' => 'Hacked',
            'lastName' => 'Hacked',
            'email' => '',
            'notes' => '',
            '_token' => $formToken,
        ]]);
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', $this->url($this->own));
        $archiveToken = (string) $crawler->filter('form[action$="/archive"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($this->foreign, '/archive'), ['_token' => $archiveToken]);
        self::assertResponseStatusCodeSame(404);

        $archivedOwn = StudentFactory::new()->archived()->create(['teacher' => $this->teacherB]);
        $crawler = $this->client->request('GET', $this->url($archivedOwn));
        $restoreToken = (string) $crawler->filter('form[action$="/restore"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->url($this->foreign, '/restore'), ['_token' => $restoreToken]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame($before, $this->snapshot($this->foreign));
    }

    public function test_foreign_student_is_not_in_the_list_or_the_search(): void
    {
        foreach (['/students', '/students?q=anna', '/students?q=meier', '/students?archived=1'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString('Meier', $crawler->filter('main')->text(), $url);
        }
        self::assertSelectorTextContains('.empty-state__title', 'No archived students');
    }

    public function test_unknown_and_malformed_ids_answer_404(): void
    {
        $this->client->request('GET', '/students/'.Uuid::v7());
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/students/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    private function url(Student $student, string $suffix = ''): string
    {
        return '/students/'.$student->getId().$suffix;
    }

    /**
     * A's student row and the number of A's audit rows.
     *
     * @return array{student: array<string, mixed>, audit: int}
     */
    private function snapshot(Student $student): array
    {
        $connection = $this->client->getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $row = $connection->fetchAssociative('SELECT * FROM student WHERE id = ?', [$student->getId()->toBinary()]);
        self::assertIsArray($row);

        return [
            'student' => $row,
            'audit' => (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_event WHERE teacher_id = ?', [$this->teacherA->getId()->toBinary()]),
        ];
    }
}
