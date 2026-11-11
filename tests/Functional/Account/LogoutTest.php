<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-2, S4: logout is a POST with a CSRF token from the sidebar, then back to /login.
 */
#[ResetDatabase]
final class LogoutTest extends WebTestCase
{
    use LogsInTeacher;

    public function test_the_sidebar_logout_form_ends_the_session(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);
        $client->request('GET', '/dashboard');

        self::assertSelectorExists('nav.sidebar form.logout-form[action="/logout"][method="post"] input[type="hidden"][name="_csrf_token"]');

        $client->submitForm('Log out');

        self::assertResponseRedirects('/login');
        $client->request('GET', '/dashboard');
        self::assertResponseRedirects('/login');
    }

    public function test_logout_refuses_get(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);

        $client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(405);
    }
}
