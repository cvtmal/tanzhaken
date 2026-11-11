<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Entity\Teacher;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-4: the account page with its profile and password cards (R9, P5).
 */
#[ResetDatabase]
final class AccountPageTest extends WebTestCase
{
    use LogsInTeacher;

    private const EMAIL = 'lena@tanzraum.example';

    public function test_anonymous_visitors_are_sent_to_login(): void
    {
        $client = $this->browser();

        $client->request('GET', '/account');

        self::assertResponseRedirects('/login');
    }

    public function test_the_page_shows_both_cards_and_the_logout_form(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, $this->teacher());

        $client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.page-header__subtitle', self::EMAIL);
        self::assertInputValueSame('profile[name]', 'Lena Keller');
        self::assertSelectorExists('select[name="profile[timezone]"] option[value="Europe/Zurich"][selected]');
        self::assertSelectorExists('form[action="/account/profile"]');
        self::assertSelectorExists('form[action="/account/password"] input[name="change_password[currentPassword]"]');
        self::assertSelectorExists('form[action="/account/password"] input[name="change_password[newPassword]"]');
        self::assertSelectorExists('main form.logout-form[action="/logout"]');
    }

    public function test_a_teacher_saves_the_profile(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, $this->teacher());
        $client->request('GET', '/account');

        $client->submitForm('Save profile', [
            'profile[name]' => 'Lena Müller',
            'profile[timezone]' => 'America/New_York',
        ]);

        self::assertResponseRedirects('/account');
        $client->followRedirect();
        self::assertSelectorTextContains('.toast__message', 'Profile saved.');
        self::assertSelectorTextContains('.sidebar__account-name', 'Lena Müller');
        self::assertSame('America/New_York', $this->connection()->fetchOne('SELECT timezone FROM teacher'));
    }

    public function test_a_blank_name_shows_the_error_in_the_profile_card_only(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, $this->teacher());
        $client->request('GET', '/account');

        $client->submitForm('Save profile', ['profile[name]' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[action="/account/profile"] .field__error', 'Enter your name.');
        self::assertSelectorNotExists('form[action="/account/password"] .field__error');
        self::assertSame('Lena Keller', $this->connection()->fetchOne('SELECT name FROM teacher'));
    }

    public function test_a_profile_post_without_csrf_token_is_refused(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, $this->teacher());
        $client->request('GET', '/account');

        $client->request('POST', '/account/profile', ['profile' => ['name' => 'Lena Müller', 'timezone' => 'Europe/Zurich']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Lena Keller', $this->connection()->fetchOne('SELECT name FROM teacher'));
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, $this->teacher());
        $client->request('GET', '/account');

        $client->submitForm('Change password', [
            'change_password[currentPassword]' => 'not-the-password',
            'change_password[newPassword]' => 'a-brand-new-password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[action="/account/password"] .field__error', 'This is not your current password.');
        self::assertSame(TeacherFactory::DEMO_PASSWORD_HASH, $this->connection()->fetchOne('SELECT password_hash FROM teacher'));
    }

    public function test_a_password_change_keeps_this_session_and_ends_the_others(): void
    {
        $client = $this->browser();
        $other = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $other);
        $teacher = $this->teacher();
        $this->logInTeacher($client, $teacher);
        $this->logInTeacher($other, $teacher);
        $other->request('GET', '/dashboard');
        self::assertSame(200, $other->getResponse()->getStatusCode());
        $client->request('GET', '/account');

        $client->submitForm('Change password', [
            'change_password[currentPassword]' => TeacherFactory::DEMO_PASSWORD,
            'change_password[newPassword]' => 'a-brand-new-password',
        ]);

        self::assertResponseRedirects('/account');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.toast__message', 'Password changed. Other devices are logged out.');
        $client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $other->request('GET', '/dashboard');
        // The firewall's entry point answers with an absolute URL.
        self::assertTrue($other->getResponse()->isRedirect('http://localhost/login'));

        $this->resetLoginThrottling($other);
        $other->request('GET', '/login');
        $other->submitForm('Log in', ['_username' => self::EMAIL, '_password' => 'a-brand-new-password']);
        self::assertSame('302 http://localhost/dashboard', $other->getResponse()->getStatusCode().' '.$other->getResponse()->headers->get('Location'));
    }

    public function test_the_card_routes_accept_only_post(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client, $this->teacher());

        $client->request('GET', '/account/profile');
        self::assertResponseStatusCodeSame(405);
        $client->request('GET', '/account/password');
        self::assertResponseStatusCodeSame(405);
    }

    private function browser(): KernelBrowser
    {
        $client = self::createClient();
        $this->resetLoginThrottling($client);

        return $client;
    }

    private function teacher(): Teacher
    {
        return TeacherFactory::createOne(['email' => self::EMAIL, 'name' => 'Lena Keller', 'timezone' => 'Europe/Zurich']);
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
