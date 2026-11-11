<?php

declare(strict_types=1);

namespace App\Tests\Unit\Account;

use App\Account\Entity\Teacher;
use App\Account\Security\TeacherOwnsVoter;
use App\Students\Entity\Student;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class TeacherOwnsVoterTest extends TestCase
{
    public function test_the_owner_is_granted(): void
    {
        $owner = self::teacher('owner@example.com');

        self::assertSame(VoterInterface::ACCESS_GRANTED, self::vote($owner, self::student($owner)));
        self::assertSame(VoterInterface::ACCESS_GRANTED, self::vote($owner, $owner));
    }

    public function test_another_teacher_is_denied(): void
    {
        $owner = self::teacher('owner@example.com');
        $other = self::teacher('other@example.com');

        self::assertSame(VoterInterface::ACCESS_DENIED, self::vote($other, self::student($owner)));
        self::assertSame(VoterInterface::ACCESS_DENIED, self::vote($other, $owner));
    }

    public function test_it_abstains_on_other_subjects_and_attributes(): void
    {
        $owner = self::teacher('owner@example.com');

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::vote($owner, new \stdClass()));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::vote($owner, null));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::vote($owner, self::student($owner), 'EDIT'));
    }

    public function test_an_anonymous_token_is_denied(): void
    {
        $student = self::student(self::teacher('owner@example.com'));

        self::assertSame(VoterInterface::ACCESS_DENIED, (new TeacherOwnsVoter())->vote(new NullToken(), $student, [TeacherOwnsVoter::ATTRIBUTE]));
    }

    private static function vote(Teacher $user, mixed $subject, string $attribute = TeacherOwnsVoter::ATTRIBUTE): int
    {
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        return (new TeacherOwnsVoter())->vote($token, $subject, [$attribute]);
    }

    private static function teacher(string $email): Teacher
    {
        return new Teacher('Teacher', $email, 'hash', new \DateTimeImmutable('2026-03-14 09:00:00'));
    }

    private static function student(Teacher $teacher): Student
    {
        return new Student($teacher, 'Anna', 'Muster', new \DateTimeImmutable('2026-03-14 09:00:00'));
    }
}
