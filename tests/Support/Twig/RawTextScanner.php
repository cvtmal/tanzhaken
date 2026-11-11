<?php

declare(strict_types=1);

namespace App\Tests\Support\Twig;

use Twig\Environment;
use Twig\Source;
use Twig\Token;

/**
 * Finds raw (untranslated) text, inline styles and inline scripts in a Twig template (S6, S7).
 *
 * Only the template's literal text is checked: every Twig expression or tag is replaced by a
 * NUL byte, Twig comments never reach the token stream, HTML comments are removed. On what is
 * left it flags style attributes, script elements without src, letters inside alt, title,
 * aria-label or placeholder values, and any letter outside a tag.
 */
final class RawTextScanner
{
    private const PLACEHOLDER = "\x00";

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @return list<string> one message per violation
     */
    public function scan(string $templateName, string $source): array
    {
        $text = $this->literalText($templateName, $source);
        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);
        $violations = [];

        if (preg_match_all('/<[^>]*\sstyle\s*=/i', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $violations[] = $this->message($templateName, 'inline style', $match);
            }
        }

        if (preg_match_all('/<script\b(?![^>]*\ssrc\s*=)[^>]*>/i', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $violations[] = $this->message($templateName, 'inline script', $match);
            }
        }

        if (preg_match_all('/\s(alt|title|aria-label|placeholder)\s*=\s*"([^"]*)"/iu', $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (1 === preg_match('/\p{L}/u', $match[2])) {
                    $violations[] = $this->message($templateName, \sprintf('raw text in %s attribute', $match[1]), $match[0]);
                }
            }
        }

        $outside = (string) preg_replace('/<[^>]*>/', ' ', $text);
        $outside = (string) preg_replace('/&[a-z0-9#]+;/i', ' ', $outside);
        if (preg_match_all('/[^\s\x00]*\p{L}[^\s\x00]*/u', $outside, $matches, \PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [$word, $offset]) {
                $excerpt = mb_strcut($outside, max(0, $offset - 15), 40);
                $violations[] = $this->message($templateName, \sprintf('raw text "%s"', $word), $excerpt);
            }
        }

        return $violations;
    }

    private function literalText(string $templateName, string $source): string
    {
        $stream = $this->twig->tokenize(new Source($source, $templateName));
        $text = '';
        $inTwig = false;

        while (!$stream->isEOF()) {
            $token = $stream->next();
            if ($token->test(Token::TEXT_TYPE)) {
                $text .= $token->getValue();
                $inTwig = false;
            } elseif (!$inTwig) {
                $text .= self::PLACEHOLDER;
                $inTwig = true;
            }
        }

        return $text;
    }

    private function message(string $templateName, string $what, string $excerpt): string
    {
        $excerpt = trim((string) preg_replace('/\s+/', ' ', str_replace(self::PLACEHOLDER, '{…}', $excerpt)));

        return \sprintf('%s: %s near "%s"', $templateName, $what, mb_substr($excerpt, 0, 40));
    }
}
