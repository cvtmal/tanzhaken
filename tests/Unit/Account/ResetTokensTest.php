<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\Service\ResetTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResetTokensTest extends TestCase
{
    public function test_it_issues_a_43_character_base64url_token_and_its_sha256_hash(): void
    {
        $tokens = new ResetTokens();
        $token = $tokens->issue();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $token->plain);
        $bytes = base64_decode(strtr($token->plain, '-_', '+/'), true);
        self::assertIsString($bytes);
        self::assertSame(32, \strlen($bytes));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $token->hash);
        self::assertSame(hash('sha256', $token->plain), $token->hash);
        self::assertSame($token->hash, $tokens->hash($token->plain));
    }

    public function test_two_issued_tokens_differ(): void
    {
        $tokens = new ResetTokens();

        $first = $tokens->issue();
        $second = $tokens->issue();

        self::assertNotSame($first->plain, $second->plain);
        self::assertNotSame($first->hash, $second->hash);
    }

    public function test_an_issued_token_is_well_formed(): void
    {
        $tokens = new ResetTokens();

        self::assertTrue($tokens->isWellFormed($tokens->issue()->plain));
    }

    #[DataProvider('malformedTokens')]
    public function test_it_rejects_a_malformed_token(string $token): void
    {
        self::assertFalse((new ResetTokens())->isWellFormed($token));
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
        yield 'trailing newline' => [str_repeat('a', 43)."\n"];
    }
}
