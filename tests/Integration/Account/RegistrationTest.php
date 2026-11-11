<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Entity\Teacher;
use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Form\RegistrationData;
use App\Account\Service\Registration;
use App\Shared\Time\Timezones;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class RegistrationTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    private const NOW = '2026-03-14 09:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
    }

    public function test_it_registers_a_teacher_with_an_audit_event(): void
    {
        $teacher = self::registration()->register(self::data('  Anna Muster ', ' Anna@Example.COM ', 'correct horse'));

        $row = self::connection()->fetchAssociative('SELECT * FROM teacher');
        self::assertIsArray($row);
        self::assertTrue(Uuid::fromBinary((string) $row['id'])->equals($teacher->getId()));
        self::assertSame('Anna Muster', $row['name']);
        self::assertSame('anna@example.com', $row['email']);
        self::assertSame(Timezones::DEFAULT, $row['timezone']);
        self::assertSame(self::NOW, $row['created_at']);

        $hasher = self::getContainer()->get(PasswordHasherFactoryInterface::class);
        self::assertInstanceOf(PasswordHasherFactoryInterface::class, $hasher);
        self::assertTrue($hasher->getPasswordHasher(Teacher::class)->verify((string) $row['password_hash'], 'correct horse'));
        self::assertNotSame('correct horse', $row['password_hash']);

        $audit = self::connection()->fetchAllAssociative('SELECT * FROM audit_event');
        self::assertCount(1, $audit);
        self::assertSame('teacher.registered', $audit[0]['action']);
        self::assertSame('teacher', $audit[0]['actor_type']);
        self::assertSame('teacher', $audit[0]['entity_type']);
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['teacher_id'])->equals($teacher->getId()));
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['entity_id'])->equals($teacher->getId()));
    }

    public function test_a_known_email_in_another_case_is_refused_before_the_transaction(): void
    {
        self::registration()->register(self::data('Anna', 'anna@example.com', 'correct horse'));

        try {
            self::registration()->register(self::data('Other Anna', ' ANNA@Example.com', 'battery staple'));
            self::fail('Expected EmailAlreadyRegistered.');
        } catch (EmailAlreadyRegistered) {
        }

        self::assertSame(1, self::countRows('teacher'));
        self::assertSame(1, self::countRows('audit_event'));
        self::assertTrue(self::em()->isOpen());
    }

    private static function data(string $name, string $email, string $password): RegistrationData
    {
        $data = new RegistrationData();
        $data->name = $name;
        $data->email = $email;
        $data->password = $password;

        return $data;
    }

    private static function registration(): Registration
    {
        $registration = self::getContainer()->get(Registration::class);
        self::assertInstanceOf(Registration::class, $registration);

        return $registration;
    }

    private static function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
