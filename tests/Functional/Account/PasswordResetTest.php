<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-3: forgot password, the reset mail and the reset link (R2–R8, P2, P3, P7).
 * The mail is sent on kernel.terminate (DeferredMailer); the test client terminates the kernel
 * after each request, so the mailer assertions see it right after the POST.
 */
#[ResetDatabase]
final class PasswordResetTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use LogsInTeacher;

    private const EMAIL = 'lena@tanzraum.example';
    private const SENT = 'If an account exists for that email, we have sent a link. It is valid for 60 minutes.';

    public function test_the_forgot_page_is_public_and_sends_teachers_to_the_dashboard(): void
    {
        $client = $this->browser();

        $client->request('GET', '/forgot-password');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Forgot your password?');

        $this->logInTeacher($client);
        $client->request('GET', '/forgot-password');
        self::assertResponseRedirects('/dashboard');
    }

    public function test_a_known_email_gets_a_plain_text_mail_with_the_link(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL, 'name' => 'Lena Keller']);

        $this->submitForgot($client, self::EMAIL);

        self::assertResponseRedirects('/forgot-password');
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'to', self::EMAIL);
        self::assertEmailAddressContains($email, 'from', 'no-reply@localhost');
        self::assertEmailHeaderSame($email, 'Subject', 'Reset your TanzHaken password');
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Hello Lena Keller,', $text);
        self::assertStringContainsString('http://localhost/reset-password/', $text);
        self::assertStringContainsString('60 minutes', $text);
        self::assertStringContainsString('If you did not ask for this, ignore this mail. Your password stays the same.', $text);
        self::assertNull($email->getHtmlBody());

        $client->followRedirect();
        self::assertSelectorTextContains('.flash-region .alert.alert--info', self::SENT);
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_mail(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL]);

        $this->submitForgot($client, self::EMAIL);
        $known = $this->answer($client);
        $this->submitForgot($client, 'nobody@tanzraum.example');
        $unknown = $this->answer($client);

        self::assertEmailCount(0);
        self::assertSame($known, $unknown);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-region .alert.alert--info', self::SENT);
    }

    public function test_the_link_uses_the_default_uri_not_the_request_host(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $client->setServerParameter('HTTP_HOST', 'evil.example');

        $this->submitForgot($client, self::EMAIL);

        self::assertStringStartsWith('http://localhost/reset-password/', $this->linkFromMail());
    }

    public function test_a_teacher_resets_the_password_with_the_link(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $this->submitForgot($client, self::EMAIL);
        $link = $this->linkFromMail();

        $client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $client->submitForm('Save new password', ['reset_password[password]' => 'a-brand-new-password']);
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-region .alert.alert--info', 'Your password is changed. Log in with the new one.');

        $this->submitLogin($client, self::EMAIL, TeacherFactory::DEMO_PASSWORD);
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorTextContains('.auth-page .alert.alert--danger', 'Email or password is wrong.');

        $this->submitLogin($client, self::EMAIL, 'a-brand-new-password');
        self::assertResponseRedirects('/dashboard');
    }

    public function test_a_used_link_answers_404_with_a_way_to_a_new_one(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $this->submitForgot($client, self::EMAIL);
        $link = $this->linkFromMail();
        $client->request('GET', $link);
        $client->submitForm('Save new password', ['reset_password[password]' => 'a-brand-new-password']);
        self::assertResponseRedirects('/login');

        $client->request('GET', $link);

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'This link is invalid or has expired');
        self::assertSelectorExists('a[href="/forgot-password"]');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
    }

    public function test_a_link_expires_after_60_minutes(): void
    {
        $client = $this->browser();
        self::mockTime('2026-03-10 20:00:00');
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $this->submitForgot($client, self::EMAIL);
        $link = $this->linkFromMail();

        self::mockTime('2026-03-10 20:59:00');
        $client->request('GET', $link);
        self::assertResponseIsSuccessful();

        self::mockTime('2026-03-10 21:00:00');
        $client->request('GET', $link);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_only_the_newest_link_works(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $this->submitForgot($client, self::EMAIL);
        $first = $this->linkFromMail();
        $this->submitForgot($client, self::EMAIL);
        $second = $this->linkFromMail();

        $client->request('GET', $first);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $second);
        self::assertResponseIsSuccessful();
    }

    public function test_the_email_limiter_stops_mails_silently(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL]);

        $mails = [];
        $answers = [];
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->submitForgot($client, self::EMAIL);
            $mails[] = \count(self::getMailerMessages());
            $answers[] = $this->answer($client);
        }

        self::assertSame([1, 1, 1, 0], $mails);
        self::assertCount(1, array_unique($answers));
        $client->followRedirect();
        self::assertSelectorTextContains('.flash-region .alert.alert--info', self::SENT);
    }

    public function test_the_ip_limiter_answers_429_after_ten_requests(): void
    {
        $client = $this->browser();

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->submitForgot($client, \sprintf('nobody%d@tanzraum.example', $attempt));
            self::assertResponseRedirects('/forgot-password');
        }
        $this->submitForgot($client, 'nobody11@tanzraum.example');

        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextContains('.auth-page .alert.alert--danger', 'Too many requests. Try again in');
    }

    public function test_an_invalid_email_shows_the_field_error(): void
    {
        $client = $this->browser();

        $this->submitForgot($client, 'not-an-email');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Enter a full email address.');
        self::assertEmailCount(0);
    }

    public function test_a_short_new_password_shows_the_field_error(): void
    {
        $client = $this->browser();
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $this->submitForgot($client, self::EMAIL);
        $client->request('GET', $this->linkFromMail());

        $client->submitForm('Save new password', ['reset_password[password]' => 'short']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field__error', 'Use 8 characters or more.');
    }

    public function test_a_reset_logs_out_the_other_sessions(): void
    {
        $client = $this->browser();
        $other = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $other);
        $teacher = TeacherFactory::createOne(['email' => self::EMAIL]);
        $this->logInTeacher($other, $teacher);
        $other->request('GET', '/dashboard');
        self::assertSame(200, $other->getResponse()->getStatusCode());

        $this->submitForgot($client, self::EMAIL);
        $client->request('GET', $this->linkFromMail());
        $client->submitForm('Save new password', ['reset_password[password]' => 'a-brand-new-password']);
        self::assertResponseRedirects('/login');

        $other->request('GET', '/dashboard');
        // The firewall's entry point answers with an absolute URL.
        self::assertTrue($other->getResponse()->isRedirect('http://localhost/login'));
    }

    private function browser(): KernelBrowser
    {
        $client = self::createClient();
        $this->resetLoginThrottling($client);

        return $client;
    }

    private function submitForgot(KernelBrowser $client, string $email): void
    {
        $client->request('GET', '/forgot-password');
        $client->submitForm('Send link', ['forgot_password[email]' => $email]);
    }

    /**
     * Status and Location of the last response: what a visitor can tell apart.
     */
    private function answer(KernelBrowser $client): string
    {
        $response = $client->getResponse();

        return $response->getStatusCode().' '.$response->headers->get('Location');
    }

    private function linkFromMail(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        if (1 !== preg_match('#https?://\S+/reset-password/[A-Za-z0-9_-]{43}#', (string) $email->getTextBody(), $match)) {
            self::fail('The mail holds no reset link.');
        }

        return $match[0];
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $this->resetLoginThrottling($client);
        $client->request('GET', '/login');
        $client->submitForm('Log in', [
            '_username' => $email,
            '_password' => $password,
        ]);
    }
}
