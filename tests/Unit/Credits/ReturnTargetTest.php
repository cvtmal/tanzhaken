<?php

declare(strict_types=1);

namespace App\Tests\Unit\Credits;

use App\Credits\Controller\ReturnTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ReturnTarget}>
     */
    public static function values(): iterable
    {
        yield 'course' => ['course', ReturnTarget::Course];
        yield 'student' => ['student', ReturnTarget::Student];
        yield 'dashboard' => ['dashboard', ReturnTarget::Dashboard];
        yield 'null' => [null, ReturnTarget::Course];
        yield 'empty' => ['', ReturnTarget::Course];
        yield 'unknown' => ['settings', ReturnTarget::Course];
        yield 'url' => ['https://evil.example/', ReturnTarget::Course];
        yield 'case differs' => ['Student', ReturnTarget::Course];
    }

    #[DataProvider('values')]
    public function test_from_query(?string $value, ReturnTarget $expected): void
    {
        self::assertSame($expected, ReturnTarget::fromQuery($value));
    }

    public function test_default_can_be_chosen(): void
    {
        self::assertSame(ReturnTarget::Student, ReturnTarget::fromQuery(null, ReturnTarget::Student));
        self::assertSame(ReturnTarget::Student, ReturnTarget::fromQuery('//evil', ReturnTarget::Student));
        self::assertSame(ReturnTarget::Course, ReturnTarget::fromQuery('course', ReturnTarget::Student));
    }
}
