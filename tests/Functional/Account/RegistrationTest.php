<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-1: registration on one screen (A2), then straight into the app logged in.
 */
#[ResetDatabase]
final class RegistrationTest extends WebTestCase
{
    use LogsInTeacher;

    public function test_register_page_shows_the_a2_fields(): void
    {
        $client = self::createClient();
        $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.layout-auth');
        self::assertSelectorExists('input[name="registration[name]"]');
        self::assertSelectorExists('input[name="registration[email]"][type="email"]');
        self::assertSelectorExists('input[name="registration[password]"][type="password"]');
        self::assertSelectorTextContains('button[type="submit"]', 'Create account');
    }

    public function test_valid_registration_logs_the_teacher_in_and_opens_the_dashboard(): void
    {
        $client = self::createClient();
        $client->request('GET', '/register');

        $client->submitForm('Create account', [
            'registration[name]' => 'Lena Keller',
            'registration[email]' => 'lena@tanzraum.example',
            'registration[password]' => 'long-enough-password',
        ]);

        self::assertResponseRedirects('/dashboard');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sidebar__account-name', 'Lena Keller');
        TeacherFactory::assert()->count(1);
    }

    public function test_an_email_registered_in_another_case_is_refused_on_the_email_field(): void
    {
        $client = self::createClient();
        TeacherFactory::createOne(['email' => 'lena@tanzraum.example']);
        $client->request('GET', '/register');

        $client->submitForm('Create account', [
            'registration[name]' => 'Lena Keller',
            'registration[email]' => 'LENA@TANZRAUM.EXAMPLE',
            'registration[password]' => 'long-enough-password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'This email is already registered. Log in instead?');
        TeacherFactory::assert()->count(1);
    }

    public function test_a_short_password_is_refused(): void
    {
        $client = self::createClient();
        $client->request('GET', '/register');

        $client->submitForm('Create account', [
            'registration[name]' => 'Lena Keller',
            'registration[email]' => 'lena@tanzraum.example',
            'registration[password]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Use 8 characters or more.');
        TeacherFactory::assert()->count(0);
    }

    public function test_a_logged_in_teacher_is_sent_to_the_dashboard(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);

        $client->request('GET', '/register');

        self::assertResponseRedirects('/dashboard');
    }
}
