<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PRIV-1: the public privacy statement. One heading per kind of stored data (D8); the operator
 * and region values come from env and carry a "Placeholder" badge outside prod, an empty value
 * shows "Not configured" (D7). Env is set before createClient(): every client boots a fresh
 * kernel, which resolves the env values at runtime.
 */
final class PrivacyPageTest extends WebTestCase
{
    private const ENV = ['APP_OPERATOR_NAME', 'APP_OPERATOR_ADDRESS', 'APP_OPERATOR_EMAIL', 'APP_HOSTING_REGION'];

    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $saved = [];

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $values) {
            if (null === $values['env']) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $values['env'];
            }
            if (null === $values['server']) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $values['server'];
            }
        }
        $this->saved = [];

        parent::tearDown();
    }

    public function test_anonymous_visitors_see_the_statement(): void
    {
        $client = $this->clientWith([]);
        $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.layout-public');
        self::assertSelectorNotExists('nav');
        self::assertSelectorTextSame('h1', 'Privacy statement');
    }

    public function test_every_data_category_has_a_heading(): void
    {
        $client = $this->clientWith([]);
        $crawler = $client->request('GET', '/privacy');

        $headings = $crawler->filter('article.privacy h3')->each(static fn (Crawler $h3): string => trim($h3->text()));

        self::assertSame([
            'Teacher account',
            'Students',
            'Courses',
            'Lessons',
            'Enrollments',
            'Passes and credits',
            'Attendance',
            'Change log',
            'Check-in and reset links',
            'Rate-limit counters',
        ], $headings);
    }

    public function test_configured_values_show_with_a_placeholder_badge_outside_prod(): void
    {
        $client = $this->clientWith([
            'APP_OPERATOR_NAME' => 'Example Dance School',
            'APP_OPERATOR_ADDRESS' => 'Example Street 1, 8000 Zürich',
            'APP_OPERATOR_EMAIL' => 'privacy@example.test',
            'APP_HOSTING_REGION' => 'Switzerland',
        ]);
        $crawler = $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('article.privacy')->text();
        foreach (['Example Dance School', 'Example Street 1, 8000 Zürich', 'privacy@example.test', 'Switzerland'] as $value) {
            self::assertStringContainsString($value, $text);
        }
        self::assertSelectorExists('a[href="mailto:privacy@example.test"]');
        self::assertSame(4, $this->badgesReading($crawler, 'Placeholder'));
        self::assertSame(0, $this->badgesReading($crawler, 'Not configured'));
    }

    public function test_empty_values_show_not_configured(): void
    {
        $client = $this->clientWith(array_fill_keys(self::ENV, ''));
        $crawler = $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->badgesReading($crawler, 'Not configured'));
        self::assertSelectorNotExists('a[href^="mailto:"]');
    }

    public function test_the_page_loads_no_third_party_resource(): void
    {
        $client = $this->clientWith([]);
        $crawler = $client->request('GET', '/privacy');

        $urls = [
            ...$crawler->filter('script[src]')->each(static fn (Crawler $node): string => (string) $node->attr('src')),
            ...$crawler->filter('link[href]')->each(static fn (Crawler $node): string => (string) $node->attr('href')),
            ...$crawler->filter('img[src]')->each(static fn (Crawler $node): string => (string) $node->attr('src')),
        ];

        self::assertNotSame([], $urls);
        foreach ($urls as $url) {
            $host = parse_url(str_starts_with($url, '//') ? 'http:'.$url : $url, \PHP_URL_HOST);
            self::assertContains($host, [null, 'localhost'], $url);
        }
    }

    /**
     * Sets the four privacy env values (absent ones keep the .env default) and boots a fresh client.
     *
     * @param array<string, string> $values
     */
    private function clientWith(array $values): KernelBrowser
    {
        foreach ($values as $name => $value) {
            if (!\array_key_exists($name, $this->saved)) {
                $this->saved[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        return self::createClient();
    }

    private function badgesReading(Crawler $crawler, string $label): int
    {
        return \count(array_filter(
            $crawler->filter('article.privacy .badge')->each(static fn (Crawler $badge): string => trim($badge->text())),
            static fn (string $text): bool => $label === $text,
        ));
    }
}
