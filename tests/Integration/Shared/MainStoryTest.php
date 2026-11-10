<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Tests\Integration\DbalRows;
use App\Tests\Story\MainStory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class MainStoryTest extends KernelTestCase
{
    use DbalRows;

    public function test_the_demo_story_loads_every_state(): void
    {
        self::bootKernel();
        MainStory::load();
        $connection = self::connection();

        self::assertSame(1, self::countRows('teacher'));
        self::assertSame(2, self::countRows('course'));
        self::assertSame(8, self::countRows('student'));
        self::assertSame(9, self::countRows('enrollment'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM enrollment WHERE left_at IS NOT NULL'));
        self::assertGreaterThanOrEqual(20, self::countRows('lesson_session'));
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM lesson_session WHERE status = 'cancelled'"));
        self::assertGreaterThanOrEqual(9, self::countRows('credit_grant'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM credit_grant WHERE voided_at IS NOT NULL'));
        self::assertGreaterThan(0, self::countRows('attendance'));
        self::assertSame(1, self::countRows('check_in_window'));
        self::assertSame(3, self::countRows('audit_event'));
    }

    public function test_the_demo_story_has_a_low_and_a_used_up_balance(): void
    {
        self::bootKernel();
        MainStory::load();

        // Test-only arithmetic to check the demo data; the product's balance is Credits' Balances (#6).
        $balances = array_map('intval', self::connection()->fetchFirstColumn(<<<'SQL'
            SELECT
                (SELECT COALESCE(SUM(g.quantity), 0) FROM credit_grant g
                    WHERE g.enrollment_id = e.id AND g.voided_at IS NULL)
              - (SELECT COUNT(*) FROM attendance a
                    JOIN lesson_session l ON l.id = a.lesson_session_id
                    WHERE l.course_id = e.course_id AND a.student_id = e.student_id AND a.status = 'present')
            FROM enrollment e
            SQL));

        self::assertCount(9, $balances);
        self::assertNotEmpty(array_filter($balances, static fn (int $balance): bool => $balance >= 1 && $balance <= 2));
        self::assertNotEmpty(array_filter($balances, static fn (int $balance): bool => $balance <= 0));
    }
}
