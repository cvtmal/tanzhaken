<?php

declare(strict_types=1);

namespace App\Tests\Unit\Credits;

use App\Credits\ReadModel\RenewalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenewalStateTest extends TestCase
{
    /**
     * @return iterable<string, array{int, RenewalState}>
     */
    public static function balances(): iterable
    {
        yield '-5' => [-5, RenewalState::RenewalNeeded];
        yield '-1' => [-1, RenewalState::RenewalNeeded];
        yield '0' => [0, RenewalState::RenewalNeeded];
        yield '1' => [1, RenewalState::Low];
        yield '2' => [2, RenewalState::Low];
        yield '3' => [3, RenewalState::Ok];
        yield '10' => [10, RenewalState::Ok];
    }

    #[DataProvider('balances')]
    public function test_from_balance(int $balance, RenewalState $expected): void
    {
        self::assertSame($expected, RenewalState::fromBalance($balance));
    }
}
