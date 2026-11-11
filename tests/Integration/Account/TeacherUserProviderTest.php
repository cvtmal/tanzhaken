<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Entity\Teacher;
use App\Account\Security\TeacherUserProvider;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TeacherUserProviderTest extends KernelTestCase
{
    use DbalRows;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function test_it_loads_a_teacher_by_mixed_case_padded_email(): void
    {
        $teacher = TeacherFactory::createOne(['email' => 'anna@example.com']);

        self::assertSame($teacher, self::provider()->loadUserByIdentifier('  Anna@Example.COM '));
    }

    public function test_an_unknown_email_is_not_found(): void
    {
        TeacherFactory::createOne(['email' => 'anna@example.com']);

        try {
            self::provider()->loadUserByIdentifier('nobody@example.com');
            self::fail('Expected UserNotFoundException.');
        } catch (UserNotFoundException $e) {
            self::assertSame('nobody@example.com', $e->getUserIdentifier());
        }
    }

    public function test_refresh_reloads_the_teacher_by_id(): void
    {
        $teacher = TeacherFactory::createOne();
        $id = $teacher->getId();
        self::em()->clear();

        $refreshed = self::provider()->refreshUser($teacher);

        self::assertNotSame($teacher, $refreshed);
        self::assertTrue($refreshed->getId()->equals($id));
        self::assertSame($teacher->getEmail(), $refreshed->getEmail());
    }

    public function test_refresh_fails_for_a_deleted_teacher(): void
    {
        $teacher = TeacherFactory::createOne();
        self::connection()->executeStatement('DELETE FROM teacher WHERE id = ?', [$teacher->getId()->toBinary()]);
        self::em()->clear();

        $this->expectException(UserNotFoundException::class);
        self::provider()->refreshUser($teacher);
    }

    public function test_refresh_rejects_other_user_classes(): void
    {
        $this->expectException(UnsupportedUserException::class);
        self::provider()->refreshUser(new InMemoryUser('someone', 'secret'));
    }

    public function test_upgrade_password_stores_the_new_hash(): void
    {
        $teacher = TeacherFactory::createOne();

        self::provider()->upgradePassword($teacher, 'new-hash');

        $stored = self::connection()->fetchOne('SELECT password_hash FROM teacher WHERE id = ?', [$teacher->getId()->toBinary()]);
        self::assertSame('new-hash', $stored);
    }

    public function test_it_supports_only_teachers(): void
    {
        self::assertTrue(self::provider()->supportsClass(Teacher::class));
        self::assertFalse(self::provider()->supportsClass(InMemoryUser::class));
    }

    private static function provider(): TeacherUserProvider
    {
        $provider = self::getContainer()->get(TeacherUserProvider::class);
        self::assertInstanceOf(TeacherUserProvider::class, $provider);

        return $provider;
    }

    private static function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
