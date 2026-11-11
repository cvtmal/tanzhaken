<?php

declare(strict_types=1);

namespace App\Tests\Integration\Credits;

use App\Credits\ReadModel\CreditHistory;
use App\Credits\ReadModel\GrantRow;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\CreditGrantFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreditHistoryTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use DbalRows;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime('2026-03-14 09:00:00');
    }

    public function test_lists_grants_newest_first_with_voided_ones(): void
    {
        $enrollment = EnrollmentFactory::createOne();
        $teacher = $enrollment->getCourse()->getTeacher();
        $old = CreditGrantFactory::createOne(['enrollment' => $enrollment, 'now' => new \DateTimeImmutable('2026-01-10 08:00:00'), 'quantity' => 10, 'note' => 'Cash']);
        $voided = CreditGrantFactory::new(['enrollment' => $enrollment, 'now' => new \DateTimeImmutable('2026-02-01 08:00:00'), 'quantity' => 5])->voided()->create();
        $new = CreditGrantFactory::createOne(['enrollment' => $enrollment, 'now' => new \DateTimeImmutable('2026-03-01 08:00:00')]);

        $history = self::history()->forStudent($teacher, $enrollment->getStudent()->getId());

        self::assertSame([$enrollment->getId()->toRfc4122()], array_keys($history));
        $rows = $history[$enrollment->getId()->toRfc4122()];
        self::assertSame(
            [$new->getId()->toRfc4122(), $voided->getId()->toRfc4122(), $old->getId()->toRfc4122()],
            array_map(static fn (GrantRow $row): string => $row->grantId->toRfc4122(), $rows),
        );
        self::assertFalse($rows[0]->isVoided());
        self::assertTrue($rows[1]->isVoided());
        self::assertSame('2026-03-14 09:00:00', $rows[1]->voidedAt?->format('Y-m-d H:i:s'));
        self::assertSame([10, 'Cash', '2026-01-10 08:00:00'], [$rows[2]->quantity, $rows[2]->note, $rows[2]->grantedAt->format('Y-m-d H:i:s')]);
        self::assertSame('UTC', $rows[2]->grantedAt->getTimezone()->getName());
        self::assertTrue($rows[2]->enrollmentId->equals($enrollment->getId()));
        self::assertNull($rows[0]->note);
    }

    public function test_groups_two_enrollments_and_ignores_other_students(): void
    {
        $student = StudentFactory::createOne();
        $teacher = $student->getTeacher();
        $salsa = EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher]), 'student' => $student]);
        $tango = EnrollmentFactory::new(['course' => CourseFactory::new(['teacher' => $teacher]), 'student' => $student])->left()->create();
        $withoutGrant = EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $teacher]), 'student' => $student]);
        CreditGrantFactory::createOne(['enrollment' => $salsa]);
        CreditGrantFactory::createOne(['enrollment' => $tango, 'quantity' => 5]);
        CreditGrantFactory::createOne(['enrollment' => $tango, 'quantity' => 3]);
        CreditGrantFactory::createOne(['enrollment' => EnrollmentFactory::new(['course' => CourseFactory::new(['teacher' => $teacher])])]);

        $history = self::history()->forStudent($teacher, $student->getId());

        self::assertCount(2, $history);
        self::assertCount(1, $history[$salsa->getId()->toRfc4122()]);
        self::assertCount(2, $history[$tango->getId()->toRfc4122()]);
        self::assertArrayNotHasKey($withoutGrant->getId()->toRfc4122(), $history);
    }

    public function test_other_teachers_student_gives_nothing(): void
    {
        $grant = CreditGrantFactory::createOne();

        self::assertSame([], self::history()->forStudent(TeacherFactory::createOne(), $grant->getEnrollment()->getStudent()->getId()));
    }

    public function test_runs_one_query(): void
    {
        $student = StudentFactory::createOne();
        for ($i = 0; $i < 5; ++$i) {
            $enrollment = EnrollmentFactory::createOne(['course' => CourseFactory::new(['teacher' => $student->getTeacher()]), 'student' => $student]);
            CreditGrantFactory::createMany(2, ['enrollment' => $enrollment]);
        }
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $holder);
        $history = self::history();

        $holder->reset();
        self::assertCount(5, $history->forStudent($student->getTeacher(), $student->getId()));

        self::assertCount(1, $holder->getData()['default'] ?? []);
    }

    private static function history(): CreditHistory
    {
        return new CreditHistory(self::connection());
    }
}
