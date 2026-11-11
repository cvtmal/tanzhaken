<?php

declare(strict_types=1);

namespace App\Tests\Integration\Students;

use App\Shared\Audit\AuditLogger;
use App\Students\Exception\InvalidStudent;
use App\Students\Exception\StudentNotFound;
use App\Students\Form\StudentData;
use App\Students\Repository\StudentRepository;
use App\Students\Service\StudentDirectory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StudentDirectoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    private const NOW = '2026-03-14 09:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
    }

    public function test_create_persists_cleaned_values_and_one_audit_row(): void
    {
        $teacher = TeacherFactory::createOne();

        $student = self::directory()->create($teacher, self::data('  Anna ', ' Meier  ', ' Anna@Example.COM ', "  Likes salsa\n "));

        $row = self::connection()->fetchAssociative('SELECT * FROM student');
        self::assertIsArray($row);
        self::assertTrue(Uuid::fromBinary((string) $row['id'])->equals($student->getId()));
        self::assertTrue(Uuid::fromBinary((string) $row['teacher_id'])->equals($teacher->getId()));
        self::assertSame('Anna', $row['first_name']);
        self::assertSame('Meier', $row['last_name']);
        self::assertSame('anna@example.com', $row['email']);
        self::assertSame('Likes salsa', $row['notes']);
        self::assertSame(self::NOW, $row['created_at']);

        $audit = self::auditRows();
        self::assertCount(1, $audit);
        self::assertSame('student.created', $audit[0]['action']);
        self::assertSame('student', $audit[0]['entity_type']);
        self::assertSame('teacher', $audit[0]['actor_type']);
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['entity_id'])->equals($student->getId()));
    }

    public function test_create_with_empty_name_is_refused_before_any_write(): void
    {
        $teacher = TeacherFactory::createOne();

        try {
            self::directory()->create($teacher, self::data(' ', 'Meier'));
            self::fail('Expected InvalidStudent.');
        } catch (InvalidStudent) {
        }

        self::assertSame(0, (int) self::connection()->fetchOne('SELECT COUNT(*) FROM student'));
        self::assertCount(0, self::auditRows());
        self::assertTrue(self::em()->isOpen());
    }

    public function test_update_changes_fields_and_audits_names_of_changed_fields_only(): void
    {
        $student = StudentFactory::createOne(['firstName' => 'Anna', 'lastName' => 'Meier', 'email' => 'old@example.com', 'notes' => 'Secret note']);

        self::directory()->update($student->getTeacher(), $student->getId(), self::data('Anna', 'Meyer', 'NEW@example.com', 'Secret note'));

        $row = self::connection()->fetchAssociative('SELECT last_name, email FROM student');
        self::assertSame(['last_name' => 'Meyer', 'email' => 'new@example.com'], $row);

        $audit = self::auditRows();
        self::assertCount(1, $audit);
        self::assertSame('student.updated', $audit[0]['action']);
        self::assertSame(['fields' => ['last_name', 'email']], $audit[0]['details']);
        self::assertStringNotContainsString('example.com', (string) $audit[0]['details_json']);
        self::assertStringNotContainsString('Secret', (string) $audit[0]['details_json']);
    }

    public function test_update_with_too_long_name_is_refused_and_keeps_the_entity_manager_open(): void
    {
        $student = StudentFactory::createOne(['lastName' => 'Meier']);

        try {
            self::directory()->update($student->getTeacher(), $student->getId(), self::data('Anna', str_repeat('x', 81)));
            self::fail('Expected InvalidStudent.');
        } catch (InvalidStudent) {
        }

        self::assertCount(0, self::auditRows());
        self::assertTrue(self::em()->isOpen());
    }

    public function test_archive_sets_the_time_from_the_clock_and_audits(): void
    {
        $student = StudentFactory::createOne();

        self::directory()->archive($student->getTeacher(), $student->getId());

        self::assertSame(self::NOW, self::connection()->fetchOne('SELECT archived_at FROM student'));
        $audit = self::auditRows();
        self::assertCount(1, $audit);
        self::assertSame('student.archived', $audit[0]['action']);
    }

    public function test_archive_twice_is_refused_with_one_audit_row_and_an_open_entity_manager(): void
    {
        $student = StudentFactory::createOne();
        self::directory()->archive($student->getTeacher(), $student->getId());

        try {
            self::directory()->archive($student->getTeacher(), $student->getId());
            self::fail('Expected InvalidStudent.');
        } catch (InvalidStudent) {
        }

        self::assertCount(1, self::auditRows());
        self::assertTrue(self::em()->isOpen());
    }

    public function test_restore_clears_the_archive_and_audits(): void
    {
        $student = StudentFactory::new()->archived()->create();

        self::directory()->restore($student->getTeacher(), $student->getId());

        self::assertNull(self::connection()->fetchOne('SELECT archived_at FROM student'));
        $audit = self::auditRows();
        self::assertCount(1, $audit);
        self::assertSame('student.restored', $audit[0]['action']);
    }

    public function test_restore_of_an_active_student_is_refused(): void
    {
        $student = StudentFactory::createOne();

        $this->expectException(InvalidStudent::class);
        self::directory()->restore($student->getTeacher(), $student->getId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writes(): iterable
    {
        yield 'update' => ['update'];
        yield 'archive' => ['archive'];
        yield 'restore' => ['restore'];
    }

    #[DataProvider('writes')]
    public function test_foreign_or_unknown_student_is_not_found_and_writes_nothing(string $write): void
    {
        $student = StudentFactory::new()->archived()->create(['firstName' => 'Anna']);
        $other = TeacherFactory::createOne();

        foreach ([$student->getId(), Uuid::v7()] as $id) {
            try {
                match ($write) {
                    'update' => self::directory()->update($other, $id, self::data('Eve', 'Intruder')),
                    'archive' => self::directory()->archive($other, $id),
                    default => self::directory()->restore($other, $id),
                };
                self::fail('Expected StudentNotFound.');
            } catch (StudentNotFound) {
            }
        }

        self::assertCount(0, self::auditRows());
        self::assertSame('Anna', self::connection()->fetchOne('SELECT first_name FROM student'));
        self::assertNotNull(self::connection()->fetchOne('SELECT archived_at FROM student'));
    }

    private static function data(string $firstName, string $lastName, ?string $email = null, ?string $notes = null): StudentData
    {
        $data = new StudentData();
        $data->firstName = $firstName;
        $data->lastName = $lastName;
        $data->email = $email;
        $data->notes = $notes;

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
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

    private static function directory(): StudentDirectory
    {
        $container = self::getContainer();
        $students = $container->get(StudentRepository::class);
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(StudentRepository::class, $students);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new StudentDirectory(self::em(), $students, new AuditLogger(self::connection(), $clock), $clock);
    }
}
