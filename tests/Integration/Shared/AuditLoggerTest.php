<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AuditLoggerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    public function test_record_writes_one_row(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-14 09:26:53');
        $teacher = TeacherFactory::createOne();
        $entityId = Uuid::v7();

        self::logger()->record($teacher->getId(), ActorType::Student, 'attendance.marked', 'attendance', $entityId, ['status' => 'present']);

        $rows = self::connection()->fetchAllAssociative('SELECT * FROM audit_event');
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertTrue(Uuid::fromBinary((string) $row['teacher_id'])->equals($teacher->getId()));
        self::assertTrue(Uuid::fromBinary((string) $row['entity_id'])->equals($entityId));
        self::assertSame('student', $row['actor_type']);
        self::assertSame('attendance.marked', $row['action']);
        self::assertSame('attendance', $row['entity_type']);
        self::assertSame('2026-03-14 09:26:53', $row['occurred_at']);
        self::assertSame(['status' => 'present'], json_decode((string) $row['details_json'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_record_joins_the_callers_transaction(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne();
        $connection = self::connection();

        $connection->beginTransaction();
        self::logger()->record($teacher->getId(), ActorType::Teacher, 'credit_grant.created', 'credit_grant', Uuid::v7());
        self::assertSame(1, self::countRows('audit_event'));
        $connection->rollBack();

        self::assertSame(0, self::countRows('audit_event'));
    }

    private static function logger(): AuditLogger
    {
        // No service uses AuditLogger yet, so the compiled container has removed it; build it
        // from the same connection and the framework clock (which follows mockTime()).
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new AuditLogger(self::connection(), $clock);
    }
}
