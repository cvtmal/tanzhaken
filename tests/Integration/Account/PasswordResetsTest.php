<?php

declare(strict_types=1);

namespace App\Tests\Integration\Account;

use App\Account\Entity\Teacher;
use App\Account\Exception\InvalidResetLink;
use App\Account\Service\DeferredMailer;
use App\Account\Service\PasswordResets;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * ACC-3: one reset request per teacher, only the token hash stored, single use, 60 minutes (R1–R3).
 */
#[ResetDatabase]
final class PasswordResetsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    private const NOW = '2026-03-10 20:00:00';
    private const EMAIL = 'lena@tanzraum.example';

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::NOW);
        // The email limiter (3 per hour) keeps its counters in this filesystem pool across tests.
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        $pool->clear();
    }

    public function test_a_known_email_stores_one_request_with_the_hash_only(): void
    {
        $teacher = TeacherFactory::createOne(['email' => self::EMAIL]);

        $plain = $this->requestLink(' LENA@tanzraum.example ');

        $rows = self::connection()->fetchAllAssociative('SELECT * FROM password_reset_request');
        self::assertCount(1, $rows);
        self::assertTrue(Uuid::fromBinary((string) $rows[0]['teacher_id'])->equals($teacher->getId()));
        self::assertSame('2026-03-10 20:00:00', $rows[0]['requested_at']);
        self::assertSame('2026-03-10 21:00:00', $rows[0]['expires_at']);
        self::assertSame(hash('sha256', $plain), $rows[0]['token_hash']);
        self::assertNotSame($plain, $rows[0]['token_hash']);
        self::assertSame(0, self::countRows('audit_event'));
    }

    public function test_a_second_request_replaces_the_first(): void
    {
        TeacherFactory::createOne(['email' => self::EMAIL]);

        $first = $this->requestLink(self::EMAIL);
        $second = $this->requestLink(self::EMAIL);

        $hashes = self::connection()->fetchFirstColumn('SELECT token_hash FROM password_reset_request');
        self::assertSame([hash('sha256', $second)], $hashes);
        self::assertNull(self::resets()->findValid($first));
        self::assertNotNull(self::resets()->findValid($second));
    }

    public function test_an_unknown_email_stores_nothing_and_sends_nothing(): void
    {
        TeacherFactory::createOne(['email' => self::EMAIL]);

        self::resets()->request('nobody@tanzraum.example');
        self::resets()->request('');
        self::flushMail();

        self::assertSame(0, self::countRows('password_reset_request'));
        self::assertEmailCount(0);
    }

    public function test_a_link_is_valid_for_60_minutes(): void
    {
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $plain = $this->requestLink(self::EMAIL);

        self::mockTime('2026-03-10 20:59:00');
        self::assertNotNull(self::resets()->findValid($plain));

        self::mockTime('2026-03-10 21:00:00');
        self::assertNull(self::resets()->findValid($plain));
    }

    public function test_a_malformed_or_unknown_token_is_not_valid(): void
    {
        self::assertNull(self::resets()->findValid('garbage'));
        self::assertNull(self::resets()->findValid(str_repeat('a', 43)));
    }

    public function test_a_reset_sets_the_password_deletes_the_request_and_is_audited(): void
    {
        $teacher = TeacherFactory::createOne(['email' => self::EMAIL]);
        $teacherId = $teacher->getId();
        $plain = $this->requestLink(self::EMAIL);

        self::resets()->reset($plain, 'a-brand-new-password');

        self::em()->clear();
        $reloaded = self::em()->find(Teacher::class, $teacherId);
        self::assertInstanceOf(Teacher::class, $reloaded);
        self::assertTrue(self::hasher()->isPasswordValid($reloaded, 'a-brand-new-password'));
        self::assertFalse(self::hasher()->isPasswordValid($reloaded, TeacherFactory::DEMO_PASSWORD));
        self::assertSame(0, self::countRows('password_reset_request'));

        $audit = self::connection()->fetchAllAssociative('SELECT * FROM audit_event');
        self::assertCount(1, $audit);
        self::assertSame('teacher.password_reset', $audit[0]['action']);
        self::assertSame('teacher', $audit[0]['actor_type']);
        self::assertSame('teacher', $audit[0]['entity_type']);
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['teacher_id'])->equals($teacherId));
        self::assertTrue(Uuid::fromBinary((string) $audit[0]['entity_id'])->equals($teacherId));
    }

    public function test_a_used_link_is_refused_before_the_transaction(): void
    {
        TeacherFactory::createOne(['email' => self::EMAIL]);
        $plain = $this->requestLink(self::EMAIL);
        self::resets()->reset($plain, 'a-brand-new-password');

        try {
            self::resets()->reset($plain, 'another-new-password');
            self::fail('A used link must be refused.');
        } catch (InvalidResetLink) {
        }

        self::assertTrue(self::em()->isOpen());
        self::assertSame(1, self::countRows('audit_event'));
    }

    public function test_deleting_the_teacher_removes_its_requests(): void
    {
        $teacher = TeacherFactory::createOne(['email' => self::EMAIL]);
        $this->requestLink(self::EMAIL);
        self::assertSame(1, self::countRows('password_reset_request'));

        self::connection()->executeStatement('DELETE FROM teacher WHERE id = ?', [$teacher->getId()->toBinary()]);

        self::assertSame(0, self::countRows('password_reset_request'));
    }

    /**
     * Requests a link and returns the plain token from the mail (the only place it exists).
     */
    private function requestLink(string $email): string
    {
        self::resets()->request($email);
        self::flushMail();

        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        if (1 !== preg_match('#/reset-password/([A-Za-z0-9_-]{43})#', (string) $message->getTextBody(), $match)) {
            self::fail('The mail holds no reset link.');
        }
        // The next request reads its own mail at index 0.
        $logger = self::getContainer()->get('mailer.message_logger_listener');
        self::assertInstanceOf(ResetInterface::class, $logger);
        $logger->reset();

        return $match[1];
    }

    /**
     * Outside an HTTP request there is no kernel.terminate; send the queued mail by hand.
     */
    private static function flushMail(): void
    {
        $mailer = self::getContainer()->get(DeferredMailer::class);
        self::assertInstanceOf(DeferredMailer::class, $mailer);
        $mailer->sendQueued();
    }

    private static function resets(): PasswordResets
    {
        $resets = self::getContainer()->get(PasswordResets::class);
        self::assertInstanceOf(PasswordResets::class, $resets);

        return $resets;
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
