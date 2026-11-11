<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * R11: with debug off, an unknown URL renders the TwigBundle 404 template in the public layout.
 */
final class ErrorPagesTest extends WebTestCase
{
    public function test_unknown_url_renders_the_404_page_in_the_public_layout(): void
    {
        $client = self::createClient(['debug' => false]);
        $client->request('GET', '/no-such-page');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('body.layout-public');
        self::assertSelectorExists('.error-page');
        self::assertSelectorTextContains('h1', 'We could not find this page');
        self::assertSelectorExists('.error-page a[href="/"]');
        self::assertSelectorNotExists('nav');
    }
}
