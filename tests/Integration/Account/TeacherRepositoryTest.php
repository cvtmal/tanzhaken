<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Repository\TeacherRepository;
use App\Tests\Factory\TeacherFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class TeacherRepositoryTest extends KernelTestCase
{
    public function test_find_one_by_email_normalises_the_input(): void
    {
        self::bootKernel();
        $teacher = TeacherFactory::createOne(['email' => 'demo@example.com']);
        $repository = self::getContainer()->get(TeacherRepository::class);
        self::assertInstanceOf(TeacherRepository::class, $repository);

        self::assertSame($teacher, $repository->findOneByEmail(' DEMO@Example.com '));
        self::assertNull($repository->findOneByEmail('someone-else@example.com'));
        self::assertNull($repository->findOneByEmail('   '));
    }
}
