<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Factory\TeacherFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Two teachers logged in side by side (two clients, two sessions) each see their own account.
 */
#[ResetDatabase]
final class TenantSeparationTest extends WebTestCase
{
    use LogsInTeacher;

    public function test_each_teacher_sees_their_own_name_in_the_sidebar(): void
    {
        $clientA = self::createClient();
        // A second browser with its own cookie jar and history (the service is not shared).
        $clientB = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $clientB);

        $this->logInTeacher($clientA, TeacherFactory::createOne(['name' => 'Lena Keller']));
        $this->logInTeacher($clientB, TeacherFactory::createOne(['name' => 'Marco Rossi']));

        self::assertSame('Lena Keller', $this->sidebarName($clientA));
        self::assertSame('Marco Rossi', $this->sidebarName($clientB));
        self::assertSame('Lena Keller', $this->sidebarName($clientA));
    }

    private function sidebarName(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/dashboard');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return trim($crawler->filter('.sidebar__account-name')->text());
    }
}
