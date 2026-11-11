<?php

declare(strict_types=1);

namespace App\Tests\Unit\Credits;

use App\Credits\ReadModel\EnrollmentBalance;
use App\Credits\ReadModel\RenewalState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class EnrollmentBalanceTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function balances(): iterable
    {
        yield 'one outstanding' => [-1, 1];
        yield 'zero' => [0, 0];
        yield 'positive' => [5, 0];
    }

    #[DataProvider('balances')]
    public function test_outstanding(int $balance, int $expected): void
    {
        $view = new EnrollmentBalance(
            Uuid::v7(), Uuid::v7(), 'Salsa', Uuid::v7(), 'Ada', 'Muster', true,
            10, 10 - $balance, $balance, RenewalState::fromBalance($balance),
        );

        self::assertSame($expected, $view->outstanding());
    }
}
