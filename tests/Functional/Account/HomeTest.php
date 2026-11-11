<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\LogsInTeacher;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * S5 `home` and ACC-5: `/` sends visitors to the login and teachers to the dashboard;
 * teacher screens need a login.
 */
#[ResetDatabase]
final class HomeTest extends WebTestCase
{
    use LogsInTeacher;

    public function test_home_sends_an_anonymous_visitor_to_the_login(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }

    public function test_home_sends_a_teacher_to_the_dashboard(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);

        $client->request('GET', '/');

        self::assertResponseRedirects('/dashboard');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function teacherScreens(): iterable
    {
        yield 'courses' => ['/courses'];
        yield 'students' => ['/students'];
        yield 'account' => ['/account'];
    }

    #[DataProvider('teacherScreens')]
    public function test_teacher_screens_send_an_anonymous_visitor_to_the_login(string $path): void
    {
        $client = self::createClient();
        $client->request('GET', $path);

        self::assertResponseRedirects('/login');
    }
}
