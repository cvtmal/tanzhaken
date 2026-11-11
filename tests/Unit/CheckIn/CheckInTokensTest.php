<?php

declare(strict_types=1);

namespace App\Tests\Unit\CheckIn;

use App\CheckIn\Service\CheckInTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckInTokensTest extends TestCase
{
    public function test_it_issues_a_43_character_base64url_token_of_32_bytes(): void
    {
        $token = (new CheckInTokens())->issue();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $token->plain);
        $bytes = base64_decode(strtr($token->plain, '-_', '+/'), true);
        self::assertIsString($bytes);
        self::assertSame(32, \strlen($bytes));
    }

    public function test_two_issued_tokens_differ(): void
    {
        $tokens = new CheckInTokens();

        $first = $tokens->issue();
        $second = $tokens->issue();

        self::assertNotSame($first->plain, $second->plain);
        self::assertNotSame($first->hash, $second->hash);
    }

    public function test_the_hash_is_the_lower_case_hex_sha256_of_the_plain_token(): void
    {
        $tokens = new CheckInTokens();
        $token = $tokens->issue();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token->hash);
        self::assertSame(hash('sha256', $token->plain), $token->hash);
        self::assertSame($token->hash, $tokens->hash($token->plain));
        self::assertNotSame($token->plain, $token->hash);
    }

    public function test_it_accepts_an_issued_token_as_well_formed(): void
    {
        $tokens = new CheckInTokens();

        self::assertTrue($tokens->isWellFormed($tokens->issue()->plain));
    }

    #[DataProvider('malformedTokens')]
    public function test_it_rejects_a_malformed_token(string $token): void
    {
        self::assertFalse((new CheckInTokens())->isWellFormed($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield '42 characters' => [str_repeat('a', 42)];
        yield '44 characters' => [str_repeat('a', 44)];
        yield 'plus' => [str_repeat('a', 42).'+'];
        yield 'slash' => [str_repeat('a', 42).'/'];
        yield 'padding' => [str_repeat('a', 42).'='];
        yield 'space' => [str_repeat('a', 42).' '];
        yield 'trailing newline' => [str_repeat('a', 43)."\n"];
        yield 'dot' => [str_repeat('a', 42).'.'];
    }
}
