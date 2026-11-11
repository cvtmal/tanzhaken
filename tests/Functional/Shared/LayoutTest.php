<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Teacher-layout pages need a logged-in teacher since #4 (ACC-5).
 */
#[ResetDatabase]
final class LayoutTest extends WebTestCase
{
    use LogsInTeacher;

    public function test_teacher_layout_has_skip_link_navigation_flash_region_and_privacy_link(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);
        $crawler = $client->request('GET', '/courses');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.layout-teacher');
        self::assertSelectorExists('a.skip-link[href="#main"]');
        self::assertSelectorExists('main#main');
        self::assertSelectorExists('.flash-region[aria-live="polite"]');
        self::assertSelectorExists('a[href="/privacy"]');

        foreach (['nav.tab-bar', 'nav.sidebar'] as $nav) {
            foreach (['/dashboard', '/courses', '/students', '/account'] as $href) {
                self::assertSelectorExists(\sprintf('%s a[href="%s"]', $nav, $href));
            }
        }

        $this->assertCurrentLinksPointTo($crawler, '/courses');
    }

    public function test_lesson_page_marks_courses_as_current(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);
        $crawler = $client->request('GET', '/courses/x/lessons/y');

        self::assertResponseIsSuccessful();
        $this->assertCurrentLinksPointTo($crawler, '/courses');
    }

    public function test_auth_layout_has_wordmark_and_no_navigation(): void
    {
        $client = self::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.layout-auth');
        self::assertSelectorNotExists('nav');
        self::assertSelectorExists('.wordmark');
        self::assertSelectorExists('a[href="/privacy"]');
    }

    public function test_public_layout_has_only_brand_content_and_privacy_link(): void
    {
        $client = self::createClient();
        $client->request('GET', '/c/abc');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.layout-public');
        self::assertSelectorNotExists('nav');
        self::assertSelectorExists('.wordmark');
        self::assertSelectorExists('a[href="/privacy"]');
    }

    public function test_title_carries_the_brand_name(): void
    {
        $client = self::createClient();
        $this->logInTeacher($client);
        $client->request('GET', '/courses');

        self::assertSelectorTextContains('title', 'TanzHaken');
    }

    private function assertCurrentLinksPointTo(Crawler $crawler, string $href): void
    {
        $current = $crawler->filter('a[aria-current="page"]');
        self::assertCount(2, $current);
        foreach ($current as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertSame($href, $link->getAttribute('href'));
        }
    }
}
