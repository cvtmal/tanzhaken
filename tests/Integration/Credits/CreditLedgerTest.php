<?php

declare(strict_types=1);

namespace App\Tests\Integration\Credits;

use App\Courses\Entity\Enrollment;
use App\Courses\Repository\EnrollmentRepository;
use App\Credits\Entity\CreditGrant;
use App\Credits\Exception\CreditNotFound;
use App\Credits\Exception\InvalidCreditGrant;
use App\Credits\ReadModel\Balances;
use App\Credits\Repository\CreditGrantRepository;
use App\Credits\Service\CreditLedger;
use App\Shared\Audit\AuditLogger;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreditLedgerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    private const NOW = '2026-03-14 09:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
    }

    public function test_grant_writes_row_and_audit(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        $teacher = $enrollment->getCourse()->getTeacher();

        $grant = self::ledger()->grant($teacher, $enrollment->getId(), 8, new \DateTimeImmutable('2026-03-10 18:30:00', new \DateTimeZone('UTC')), '  Spring pass ');

        $row = self::connection()->fetchAssociative('SELECT * FROM credit_grant');
        self::assertIsArray($row);
        self::assertTrue(Uuid::fromBinary((string) $row['id'])->equals($grant->getId()));
        self::assertTrue(Uuid::fromBinary((string) $row['enrollment_id'])->equals($enrollment->getId()));
        self::assertSame(8, (int) $row['quantity']);
        self::assertSame('2026-03-10 18:30:00', $row['granted_at']);
        self::assertSame('Spring pass', $row['note']);
        self::assertNull($row['voided_at']);

        $audit = self::auditRows();
        self::assertCount(1, $audit);
        self::assertSame(['teacher', 'credit_grant.created', 'credit_grant'], [$audit[0]['actor_type'], $audit[0]['action'], $audit[0]['entity_type']]);
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['entity_id'])->equals($grant->getId()));
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['teacher_id'])->equals($teacher->getId()));
        self::assertSame(['quantity' => 8, 'granted_at' => '2026-03-10 18:30:00'], $audit[0]['details']);
    }

    public function test_grant_defaults_to_ten_and_now(): void
    {
        $enrollment = EnrollmentFactory::createOne();

        $grant = self::ledger()->grant($enrollment->getCourse()->getTeacher(), $enrollment->getId());

        self::assertSame(10, $grant->getQuantity());
        self::assertSame(self::NOW, $grant->getGrantedAt()->format('Y-m-d H:i:s'));
        self::assertNull($grant->getNote());
        self::assertSame(1, self::countRows('credit_grant'));
    }

    public function test_grant_refuses_zero_quantity_and_writes_nothing(): void
    {
        $enrollment = EnrollmentFactory::createOne();

        self::assertRefusedAndNothingWritten(
            static fn () => self::ledger()->grant($enrollment->getCourse()->getTeacher(), $enrollment->getId(), 0),
            InvalidCreditGrant::class,
        );
    }

    public function test_grant_refuses_future_date(): void
    {
        $enrollment = EnrollmentFactory::createOne();

        self::assertRefusedAndNothingWritten(
            static fn () => self::ledger()->grant($enrollment->getCourse()->getTeacher(), $enrollment->getId(), 10, new \DateTimeImmutable('2026-03-14 09:00:01', new \DateTimeZone('UTC'))),
            InvalidCreditGrant::class,
            'future',
        );
    }

    public function test_grant_stores_non_utc_date_as_utc(): void
    {
        $enrollment = EnrollmentFactory::createOne();

        self::ledger()->grant($enrollment->getCourse()->getTeacher(), $enrollment->getId(), 10, new \DateTimeImmutable('2026-03-14 09:30:00', new \DateTimeZone('Europe/Zurich')));

        self::assertSame('2026-03-14 08:30:00', self::connection()->fetchOne('SELECT granted_at FROM credit_grant'));
    }

    public function test_grant_on_foreign_or_unknown_enrollment_throws_not_found(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        $stranger = TeacherFactory::createOne();

        self::assertRefusedAndNothingWritten(static fn () => self::ledger()->grant($stranger, $enrollment->getId()), CreditNotFound::class);
        self::assertRefusedAndNothingWritten(static fn () => self::ledger()->grant($enrollment->getCourse()->getTeacher(), Uuid::v7()), CreditNotFound::class);
    }

    public function test_grant_refuses_left_enrollment(): void
    {
        $enrollment = EnrollmentFactory::new()->left()->create();

        self::assertGrantRefusedAsInactive($enrollment);
    }

    public function test_grant_refuses_archived_course(): void
    {
        $enrollment = EnrollmentFactory::createOne(['course' => CourseFactory::new()->archived()]);

        self::assertGrantRefusedAsInactive($enrollment);
    }

    public function test_grant_refuses_archived_student(): void
    {
        $course = CourseFactory::createOne();
        $enrollment = EnrollmentFactory::createOne(['course' => $course, 'student' => StudentFactory::new(['teacher' => $course->getTeacher()])->archived()]);

        self::assertGrantRefusedAsInactive($enrollment);
    }

    public function test_void_sets_voided_at_and_audits_reason(): void
    {
        $grant = CreditGrantFactory::createOne(['quantity' => 7]);

        self::ledger()->void(self::teacherOf($grant), $grant->getId(), ' Entered twice ');

        self::assertSame(self::NOW, self::connection()->fetchOne('SELECT voided_at FROM credit_grant'));
        $audit = self::auditRows();
        self::assertCount(1, $audit);
        self::assertSame('credit_grant.voided', $audit[0]['action']);
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['entity_id'])->equals($grant->getId()));
        self::assertSame(['quantity' => 7, 'reason' => 'Entered twice'], $audit[0]['details']);
    }

    public function test_void_without_reason_has_no_reason_key(): void
    {
        $grant = CreditGrantFactory::createOne();

        self::ledger()->void(self::teacherOf($grant), $grant->getId(), '   ');

        self::assertSame(['quantity' => 10], self::auditRows()[0]['details']);
    }

    public function test_void_reason_too_long_refused(): void
    {
        $grant = CreditGrantFactory::createOne();

        self::assertRefusedAndNothingWritten(
            static fn () => self::ledger()->void(self::teacherOf($grant), $grant->getId(), str_repeat('x', CreditLedger::REASON_MAX + 1)),
            InvalidCreditGrant::class,
            'reason',
            grants: 1,
        );
        self::assertNull(self::connection()->fetchOne('SELECT voided_at FROM credit_grant'));
    }

    public function test_second_void_refused_one_audit_row(): void
    {
        $grant = CreditGrantFactory::createOne();
        $teacher = self::teacherOf($grant);
        self::ledger()->void($teacher, $grant->getId());

        try {
            self::ledger()->void($teacher, $grant->getId());
            self::fail('A second void must be refused.');
        } catch (InvalidCreditGrant) {
        }

        self::assertSame(1, self::countRows('audit_event'));
        self::assertTrue(self::em()->isOpen());
    }

    public function test_void_on_foreign_grant_throws_not_found(): void
    {
        $grant = CreditGrantFactory::createOne();

        self::assertRefusedAndNothingWritten(static fn () => self::ledger()->void(TeacherFactory::createOne(), $grant->getId()), CreditNotFound::class, grants: 1);
        self::assertNull(self::connection()->fetchOne('SELECT voided_at FROM credit_grant'));
    }

    public function test_void_allowed_on_left_enrollment(): void
    {
        $grant = CreditGrantFactory::createOne();
        $grant->getEnrollment()->leave(self::clock()->now());
        self::em()->flush();

        self::ledger()->void(self::teacherOf($grant), $grant->getId());

        self::assertSame(self::NOW, self::connection()->fetchOne('SELECT voided_at FROM credit_grant'));
    }

    public function test_correct_voids_old_and_grants_new_with_linked_audit(): void
    {
        $old = CreditGrantFactory::createOne(['quantity' => 10]);

        $new = self::ledger()->correct(self::teacherOf($old), $old->getId(), 8, null, 'Corrected pass', 'Wrong size');

        self::assertTrue($new->getEnrollment()->getId()->equals($old->getEnrollment()->getId()));
        self::assertSame(8, $new->getQuantity());
        self::assertSame('Corrected pass', $new->getNote());
        $voided = self::connection()->fetchOne('SELECT voided_at FROM credit_grant WHERE id = ?', [$old->getId()], ['uuid']);
        self::assertSame(self::NOW, $voided);
        self::assertSame(2, self::countRows('credit_grant'));

        $audit = self::auditRows();
        self::assertCount(2, $audit);
        $byAction = array_column($audit, null, 'action');
        self::assertSame(
            ['quantity' => 10, 'reason' => 'Wrong size', 'replaced_by' => $new->getId()->toRfc4122()],
            $byAction['credit_grant.voided']['details'],
        );
        self::assertTrue(Uuid::fromBinary((string) $byAction['credit_grant.voided']['entity_id'])->equals($old->getId()));
        self::assertSame(
            ['quantity' => 8, 'granted_at' => self::NOW, 'corrects' => $old->getId()->toRfc4122()],
            $byAction['credit_grant.created']['details'],
        );
        self::assertTrue(Uuid::fromBinary((string) $byAction['credit_grant.created']['entity_id'])->equals($new->getId()));
    }

    public function test_refused_correction_changes_nothing(): void
    {
        $old = CreditGrantFactory::createOne();

        self::assertRefusedAndNothingWritten(static fn () => self::ledger()->correct(self::teacherOf($old), $old->getId(), 0), InvalidCreditGrant::class, grants: 1);
        self::assertNull(self::connection()->fetchOne('SELECT voided_at FROM credit_grant'));
    }

    public function test_balance_after_correction(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        $teacher = $enrollment->getCourse()->getTeacher();
        $ledger = self::ledger();
        $grant = $ledger->grant($teacher, $enrollment->getId());

        $ledger->correct($teacher, $grant->getId(), 8);

        $balance = (new Balances(self::connection()))->forEnrollment($teacher, $enrollment->getId());
        self::assertNotNull($balance);
        self::assertSame(8, $balance->credits);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    private static function assertRefusedAndNothingWritten(callable $call, string $exception, ?string $messagePart = null, int $grants = 0): void
    {
        try {
            $call();
            self::fail(\sprintf('Expected %s.', $exception));
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
            if (null !== $messagePart) {
                self::assertStringContainsString($messagePart, $e->getMessage());
            }
        }

        self::assertSame($grants, self::countRows('credit_grant'));
        self::assertSame(0, self::countRows('audit_event'));
        self::assertTrue(self::em()->isOpen(), 'A refusal must not close the EntityManager.');
    }

    private static function assertGrantRefusedAsInactive(Enrollment $enrollment): void
    {
        self::assertRefusedAndNothingWritten(
            static fn () => self::ledger()->grant($enrollment->getCourse()->getTeacher(), $enrollment->getId()),
            InvalidCreditGrant::class,
            'active enrollment',
        );
    }

    private static function teacherOf(CreditGrant $grant): \App\Account\Entity\Teacher
    {
        return $grant->getEnrollment()->getCourse()->getTeacher();
    }

    /**
     * @return list<array<string, mixed>> audit rows in insertion order, `details` decoded
     */
    private static function auditRows(): array
    {
        $rows = self::connection()->fetchAllAssociative('SELECT * FROM audit_event ORDER BY id');

        return array_map(static function (array $row): array {
            $row['details'] = json_decode((string) $row['details_json'], true, flags: \JSON_THROW_ON_ERROR);

            return $row;
        }, $rows);
    }

    private static function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private static function clock(): ClockInterface
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return $clock;
    }

    private static function ledger(): CreditLedger
    {
        // No service uses CreditLedger (or AuditLogger) yet, so the compiled container has removed
        // them; build the ledger from the container's parts and the framework clock (which follows
        // mockTime()). #12 injects it into its controllers.
        $container = self::getContainer();
        $enrollments = $container->get(EnrollmentRepository::class);
        $grants = $container->get(CreditGrantRepository::class);
        self::assertInstanceOf(EnrollmentRepository::class, $enrollments);
        self::assertInstanceOf(CreditGrantRepository::class, $grants);

        return new CreditLedger(self::em(), $enrollments, $grants, new AuditLogger(self::connection(), self::clock()), self::clock());
    }
}
