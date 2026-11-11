<?php

declare(strict_types=1);

namespace App\Tests\Unit\CheckIn;

use App\CheckIn\Service\QrCodeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The inline SVG of the teacher's QR page (TEC-5, S6): embeddable in HTML and sized by CSS.
 */
final class QrCodeRendererTest extends TestCase
{
    private const URL = 'http://localhost/c/functional-test-aaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_it_renders_an_inline_svg_sized_by_its_view_box(): void
    {
        $svg = (new QrCodeRenderer())->svg(self::URL);

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('viewBox=', $svg);
        if (1 !== preg_match('/<svg\b[^>]*>/', $svg, $root)) {
            self::fail('The SVG has a root tag.');
        }
        self::assertStringNotContainsString('width=', $root[0]);
        self::assertStringNotContainsString('height=', $root[0]);
        self::assertStringNotContainsString('<?xml', $svg);
        self::assertStringNotContainsString('style', $svg);
        self::assertStringNotContainsString('<script', $svg);
    }

    public function test_the_same_url_renders_the_same_svg(): void
    {
        $renderer = new QrCodeRenderer();

        self::assertSame($renderer->svg(self::URL), $renderer->svg(self::URL));
    }

    public function test_a_different_url_renders_a_different_svg(): void
    {
        $renderer = new QrCodeRenderer();

        self::assertNotSame($renderer->svg(self::URL), $renderer->svg('http://localhost/c/rotated-token-ccccccccccccccccccccccccccccc'));
    }
}
