<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * R11: every error template renders outside a request (no user, no query) with its heading and the link home.
 */
final class ErrorPagesTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, int, string, string}>
     */
    public static function errorTemplates(): iterable
    {
        yield '403' => ['@Twig/Exception/error403.html.twig', 403, 'Forbidden', 'This page is not open to you'];
        yield '500' => ['@Twig/Exception/error500.html.twig', 500, 'Internal Server Error', 'Something went wrong on our side'];
        yield 'generic' => ['@Twig/Exception/error.html.twig', 418, 'I\'m a teapot', 'Something went wrong'];
    }

    #[DataProvider('errorTemplates')]
    public function test_error_template_renders_heading_and_home_link(string $template, int $code, string $text, string $heading): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render($template, ['status_code' => $code, 'status_text' => $text]);

        self::assertStringContainsString('class="layout-public"', $html);
        self::assertStringContainsString('class="error-page"', $html);
        self::assertStringContainsString('<h1 class="error-page__heading">'.$heading.'</h1>', $html);
        self::assertMatchesRegularExpression('#<a href="/" class="btn[^"]*">#', $html);
    }

    public function test_generic_template_shows_the_status_code(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('@Twig/Exception/error.html.twig', ['status_code' => 418, 'status_text' => 'I\'m a teapot']);

        self::assertStringContainsString('<title>Error 418 · TanzHaken</title>', $html);
        self::assertStringContainsString('(error 418)', $html);
    }
}
