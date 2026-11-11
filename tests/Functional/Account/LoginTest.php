<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-2, SEC-1: email + password login with CSRF, the error states and the login limiter.
 * Each test GETs /login before it submits: the stateless CSRF check reads the Referer.
 */
#[ResetDatabase]
final class LoginTest extends WebTestCase
{
    use LogsInTeacher;

    public function test_a_teacher_logs_in_and_lands_on_the_dashboard(): void
    {
        $client = $this->browser();
        $teacher = TeacherFactory::createOne(['email' => 'lena@tanzraum.example']);

        $this->submitLogin($client, 'lena@tanzraum.example', TeacherFactory::DEMO_PASSWORD);

        self::assertResponseRedirects('/dashboard');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sidebar__account-name', $teacher->getDisplayName());
    }

    public function test_the_email_is_matched_in_any_case(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => 'lena@tanzraum.example']);

        $this->submitLogin($client, '  LENA@TanzRaum.example ', TeacherFactory::DEMO_PASSWORD);

        self::assertResponseRedirects('/dashboard');
    }

    public function test_a_wrong_password_shows_the_error_and_keeps_the_visitor_anonymous(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => 'lena@tanzraum.example']);

        $this->submitLogin($client, 'lena@tanzraum.example', 'not-the-password');

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.auth-page .alert.alert--danger[role="alert"]', 'Email or password is wrong.');

        $client->request('GET', '/courses');
        self::assertResponseRedirects('/login');
    }

    public function test_five_failures_throttle_even_the_right_password(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => 'lena@tanzraum.example']);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submitLogin($client, 'lena@tanzraum.example', 'not-the-password');
        }
        $this->submitLogin($client, 'lena@tanzraum.example', TeacherFactory::DEMO_PASSWORD);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.auth-page .alert.alert--danger', 'Too many attempts. Try again in');

        $client->request('GET', '/courses');
        self::assertResponseRedirects('/login');
    }

    public function test_a_logged_in_teacher_is_sent_to_the_dashboard(): void
    {
        $client = $this->browser();
        $this->logInTeacher($client);

        $client->request('GET', '/login');

        self::assertResponseRedirects('/dashboard');
    }

    public function test_a_post_without_csrf_token_shows_the_expired_message(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => 'lena@tanzraum.example']);
        $client->request('GET', '/login');

        $client->request('POST', '/login', [
            '_username' => 'lena@tanzraum.example',
            '_password' => TeacherFactory::DEMO_PASSWORD,
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.auth-page .alert.alert--danger', 'The page expired. Please try again.');
    }

    private function browser(): KernelBrowser
    {
        $client = self::createClient();
        $this->resetLoginThrottling($client);

        return $client;
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => $email,
            '_password' => $password,
        ]);
    }
}
