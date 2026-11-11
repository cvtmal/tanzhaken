<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Entity\Teacher;
use App\Account\Exception\InvalidTeacher;
use App\Account\Form\ProfileData;
use App\Account\Service\AccountSettings;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-4: profile (name, time zone) and password change from the account page (R9, P5).
 */
#[ResetDatabase]
final class AccountSettingsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-10 20:00:00');
    }

    public function test_a_new_name_and_time_zone_are_stored_and_audited(): void
    {
        $teacher = TeacherFactory::createOne(['name' => 'Lena Keller', 'timezone' => 'Europe/Zurich']);

        self::settings()->updateProfile($teacher, self::profile('Lena Müller', 'America/New_York'));

        $row = self::connection()->fetchAssociative('SELECT name, timezone FROM teacher');
        self::assertSame(['name' => 'Lena Müller', 'timezone' => 'America/New_York'], $row);

        $audit = self::connection()->fetchAllAssociative('SELECT * FROM audit_event');
        self::assertCount(1, $audit);
        self::assertSame('teacher.profile_updated', $audit[0]['action']);
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['teacher_id'])->equals($teacher->getId()));
        self::assertSame(['changed' => ['name', 'timezone']], json_decode((string) $audit[0]['details_json'], true));
    }

    public function test_unchanged_values_write_no_audit_event(): void
    {
        $teacher = TeacherFactory::createOne(['name' => 'Lena Keller', 'timezone' => 'Europe/Zurich']);

        self::settings()->updateProfile($teacher, self::profile('  Lena Keller ', 'Europe/Zurich'));

        self::assertSame('Lena Keller', self::connection()->fetchOne('SELECT name FROM teacher'));
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_an_invalid_time_zone_is_refused_before_the_transaction(): void
    {
        $teacher = TeacherFactory::createOne(['name' => 'Lena Keller', 'timezone' => 'Europe/Zurich']);

        try {
            self::settings()->updateProfile($teacher, self::profile('Lena Müller', 'Mars/Olympus_Mons'));
            self::fail('An invalid time zone must be refused.');
        } catch (InvalidTeacher) {
        }

        self::assertTrue(self::em()->isOpen());
        $row = self::connection()->fetchAssociative('SELECT name, timezone FROM teacher');
        self::assertSame(['name' => 'Lena Keller', 'timezone' => 'Europe/Zurich'], $row);
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_a_password_change_is_audited_and_kills_pending_reset_links(): void
    {
        $teacher = TeacherFactory::createOne();
        $other = TeacherFactory::createOne();
        self::pendingReset($teacher);
        self::pendingReset($other);

        self::settings()->changePassword($teacher, 'a-brand-new-password');

        self::em()->clear();
        $reloaded = self::em()->find(Teacher::class, $teacher->getId());
        self::assertInstanceOf(Teacher::class, $reloaded);
        self::assertTrue(self::hasher()->isPasswordValid($reloaded, 'a-brand-new-password'));
        self::assertFalse(self::hasher()->isPasswordValid($reloaded, TeacherFactory::DEMO_PASSWORD));

        $owners = self::connection()->fetchFirstColumn('SELECT teacher_id FROM password_reset_request');
        self::assertCount(1, $owners);
        self::assertTrue(Uuid::fromBinary((string) $owners[0])->equals($other->getId()));

        $audit = self::connection()->fetchAllAssociative('SELECT * FROM audit_event');
        self::assertCount(1, $audit);
        self::assertSame('teacher.password_changed', $audit[0]['action']);
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['teacher_id'])->equals($teacher->getId()));
    }

    private static function pendingReset(Teacher $teacher): void
    {
        $now = new \DateTimeImmutable('2026-03-10 20:00:00');
        self::insertRow('password_reset_request', [
            'id' => Uuid::v7(),
            'token_hash' => hash('sha256', random_bytes(32)),
            'requested_at' => $now,
            'expires_at' => $now->modify('+60 minutes'),
            'teacher_id' => $teacher->getId(),
        ]);
    }

    private static function profile(string $name, string $timezone): ProfileData
    {
        $data = new ProfileData();
        $data->name = $name;
        $data->timezone = $timezone;

        return $data;
    }

    private static function settings(): AccountSettings
    {
        $settings = self::getContainer()->get(AccountSettings::class);
        self::assertInstanceOf(AccountSettings::class, $settings);

        return $settings;
    }

    private static function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    private static function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
