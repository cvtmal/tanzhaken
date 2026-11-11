<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use App\Tests\Support\Twig\RawTextScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class RawTextScannerTest extends TestCase
{
    private RawTextScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new RawTextScanner(new Environment(new ArrayLoader()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function violations(): iterable
    {
        yield 'text node' => ['<p>Hello</p>', 'raw text "Hello"'];
        yield 'placeholder attribute' => ['<input placeholder="Name">', 'raw text in placeholder attribute'];
        yield 'aria-label attribute' => ['<a aria-label="Close" href="#">{{ x }}</a>', 'raw text in aria-label attribute'];
        yield 'inline style' => ['<div style="x">{{ x }}</div>', 'inline style'];
        yield 'inline script' => ['<script>x</script>', 'inline script'];
        yield 'text around twig' => ["{{ 'a'|trans }} and {{ 'b'|trans }}", 'raw text "and"'];
    }

    #[DataProvider('violations')]
    public function test_flags(string $source, string $expected): void
    {
        $violations = $this->scanner->scan('t.html.twig', $source);

        self::assertNotSame([], $violations);
        self::assertStringContainsString($expected, implode("\n", $violations));
        self::assertStringStartsWith('t.html.twig: ', $violations[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clean(): iterable
    {
        yield 'translated text' => ["<p>{{ 'k'|trans }}</p>"];
        yield 'attributes with expressions' => ["<a href=\"{{ path('x') }}\" class=\"btn\">{{ label }}</a>"];
        yield 'svg path data' => ['<svg><path d="M5 13l4"/></svg>'];
        yield 'external script' => ['<script src="/a.js"></script>'];
        yield 'twig comment' => ['{# Comment text #}'];
        yield 'html comment' => ['<!-- note for developers -->'];
        yield 'entity' => ['&middot;'];
        yield 'separator between expressions' => ["{{ 'a'|trans }} · {{ brand_name }}"];
        yield 'tag in conditional' => ['<a class="x"{% if a %} aria-current="page"{% endif %}>{{ a }}</a>'];
        yield 'doctype' => ['<!DOCTYPE html><html lang="{{ l }}"></html>'];
    }

    #[DataProvider('clean')]
    public function test_passes(string $source): void
    {
        self::assertSame([], $this->scanner->scan('t.html.twig', $source));
    }
}
