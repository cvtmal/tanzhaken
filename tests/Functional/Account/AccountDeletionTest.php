<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Entity\Teacher;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-6: the delete card of /account. The password confirms; success removes the teacher's
 * rows, ends every session of the teacher and lands on /login with an info alert (D3, D4).
 */
#[ResetDatabase]
final class AccountDeletionTest extends WebTestCase
{
    use LogsInTeacher;

    public function test_a_wrong_password_deletes_nothing(): void
    {
        $client = $this->browser();
        $teacher = $this->logInTeacher($client, TeacherFactory::createOne());
        $client->request('GET', '/account');

        $client->submitForm('Delete account', ['delete_account[password]' => 'wrong']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[action="/account/delete"] .field__error', 'This is not your password.');
        self::assertSame(1, $this->rowsOf('teacher', 'id', $teacher));
    }

    public function test_the_teacher_is_deleted_logged_out_and_told(): void
    {
        $client = $this->browser();
        $teacher = TeacherFactory::createOne();
        StudentFactory::createOne(['teacher' => $teacher]);
        CourseFactory::createOne(['teacher' => $teacher]);
        $kept = TeacherFactory::createOne();
        StudentFactory::createOne(['teacher' => $kept]);
        CourseFactory::createOne(['teacher' => $kept]);
        $this->logInTeacher($client, $teacher);
        $client->request('GET', '/account');

        $client->submitForm('Delete account', ['delete_account[password]' => TeacherFactory::DEMO_PASSWORD]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert', 'Your account and all its data were deleted.');
        $client->request('GET', '/account');
        self::assertTrue($client->getResponse()->isRedirect('http://localhost/login'));

        self::assertSame(0, $this->rowsOf('teacher', 'id', $teacher));
        self::assertSame(0, $this->rowsOf('student', 'teacher_id', $teacher));
        self::assertSame(0, $this->rowsOf('course', 'teacher_id', $teacher));
        self::assertSame(1, $this->rowsOf('teacher', 'id', $kept));
        self::assertSame(1, $this->rowsOf('student', 'teacher_id', $kept));
        self::assertSame(1, $this->rowsOf('course', 'teacher_id', $kept));
    }

    public function test_other_sessions_of_the_teacher_end(): void
    {
        $client = $this->browser();
        $other = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $other);
        $teacher = TeacherFactory::createOne();
        $this->logInTeacher($client, $teacher);
        $this->logInTeacher($other, $teacher);
        $other->request('GET', '/dashboard');
        self::assertSame(200, $other->getResponse()->getStatusCode());
        $client->request('GET', '/account');

        $client->submitForm('Delete account', ['delete_account[password]' => TeacherFactory::DEMO_PASSWORD]);
        self::assertResponseRedirects('/login');

        $other->request('GET', '/dashboard');
        // The firewall's entry point answers with an absolute URL.
        self::assertTrue($other->getResponse()->isRedirect('http://localhost/login'));
    }

    public function test_a_post_without_csrf_token_deletes_nothing(): void
    {
        $client = $this->browser();
        $teacher = $this->logInTeacher($client, TeacherFactory::createOne());

        $client->request('POST', '/account/delete', ['delete_account' => ['password' => TeacherFactory::DEMO_PASSWORD]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->rowsOf('teacher', 'id', $teacher));
    }

    public function test_the_route_accepts_only_post(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, TeacherFactory::createOne());

        $client->request('GET', '/account/delete');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_the_card_and_dialog_render(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, TeacherFactory::createOne());

        $client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/account/delete"] input[name="delete_account[password]"]');
        self::assertSelectorExists('form[action="/account/delete"] button[data-dialog-open="account-delete-confirm"]');
        self::assertSelectorExists('dialog#account-delete-confirm button[data-dialog-confirm]');
        // page_scripts gains 'dialog' (OD-1): importmap() prints an import of the entry point.
        self::assertStringContainsString("import 'dialog';", (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('dialog#account-delete-confirm', 'All your data is removed now and for good.');
    }

    private function browser(): KernelBrowser
    {
        $client = self::createClient();
        $this->resetLoginThrottling($client);

        return $client;
    }

    private function rowsOf(string $table, string $column, Teacher $teacher): int
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return (int) $connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column),
            [$teacher->getId()->toBinary()],
        );
    }
}
