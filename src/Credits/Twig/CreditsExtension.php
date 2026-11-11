<?php

declare(strict_types=1);

namespace App\Credits\Twig;

use App\Account\Entity\Teacher;
use App\Courses\Repository\CourseRepository;
use App\Credits\ReadModel\Balances;
use App\Credits\ReadModel\CreditHistory;
use App\Credits\ReadModel\EnrollmentBalance;
use App\Credits\ReadModel\GrantRow;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * Credit facts for the `only` slots of other modules' pages (PD10): a slot cannot receive them
 * from a controller it does not own, so it asks these functions. Each reads the current teacher
 * from Security (no Teacher user: empty results) and memoises its read-model call per id for the
 * request, so a roster of n rows still runs one Balances query (PERF-1). Ids may be a Uuid or an
 * RFC 4122 string (`course_slot.id` is a string).
 *
 * - `credits_student(student_id)`: `{balances: list<EnrollmentBalance>, history: array<enrollment id, list<GrantRow>>}`
 * - `credits_course_balance(course_id, enrollment_id)`: ?EnrollmentBalance
 * - `credits_renewal_count(course_id)`: int, active enrollments of the course at or below zero
 * - `credits_course_open(course_id)`: bool, the teacher's course exists and is not archived
 */
final class CreditsExtension implements ResetInterface
{
    /** @var array<string, array<string, EnrollmentBalance>> by teacher|course id */
    private array $courseBalances = [];

    /** @var array<string, array{balances: list<EnrollmentBalance>, history: array<string, list<GrantRow>>}> by teacher|student id */
    private array $students = [];

    /** @var array<string, array<string, int>> by teacher id, then course id */
    private array $renewalCounts = [];

    /** @var array<string, array<string, true>> by teacher id, then course id (RFC 4122) */
    private array $openCourses = [];

    public function __construct(
        private readonly Balances $balances,
        private readonly CreditHistory $history,
        private readonly CourseRepository $courses,
        private readonly Security $security,
    ) {
    }

    /**
     * @return array{balances: list<EnrollmentBalance>, history: array<string, list<GrantRow>>}
     */
    #[AsTwigFunction('credits_student')]
    public function student(Uuid|string $studentId): array
    {
        $teacher = $this->teacher();
        if (null === $teacher) {
            return ['balances' => [], 'history' => []];
        }
        $id = self::uuid($studentId);
        $key = self::key($teacher, $id);

        return $this->students[$key] ??= [
            'balances' => $this->balances->forStudent($teacher, $id),
            'history' => $this->history->forStudent($teacher, $id),
        ];
    }

    #[AsTwigFunction('credits_course_balance')]
    public function courseBalance(Uuid|string $courseId, Uuid|string $enrollmentId): ?EnrollmentBalance
    {
        $teacher = $this->teacher();
        if (null === $teacher) {
            return null;
        }
        $id = self::uuid($courseId);
        $balances = $this->courseBalances[self::key($teacher, $id)] ??= $this->balances->forCourse($teacher, $id);

        return $balances[self::uuid($enrollmentId)->toRfc4122()] ?? null;
    }

    #[AsTwigFunction('credits_renewal_count')]
    public function renewalCount(Uuid|string $courseId): int
    {
        $teacher = $this->teacher();
        if (null === $teacher) {
            return 0;
        }
        $counts = $this->renewalCounts[$teacher->getId()->toRfc4122()] ??= $this->countByCourse($teacher);

        return $counts[self::uuid($courseId)->toRfc4122()] ?? 0;
    }

    #[AsTwigFunction('credits_course_open')]
    public function courseOpen(Uuid|string $courseId): bool
    {
        $teacher = $this->teacher();
        if (null === $teacher) {
            return false;
        }
        // One query per request for all of the teacher's open courses, so a student page with n
        // courses does not run n queries (PERF-1).
        $open = $this->openCourses[$teacher->getId()->toRfc4122()] ??= $this->openCourseIds($teacher);

        return isset($open[self::uuid($courseId)->toRfc4122()]);
    }

    public function reset(): void
    {
        $this->courseBalances = [];
        $this->students = [];
        $this->renewalCounts = [];
        $this->openCourses = [];
    }

    /**
     * @return array<string, int> by course id (RFC 4122)
     */
    private function countByCourse(Teacher $teacher): array
    {
        $counts = [];
        foreach ($this->balances->renewalNeeded($teacher) as $balance) {
            $course = $balance->courseId->toRfc4122();
            $counts[$course] = ($counts[$course] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, true> the ids (RFC 4122) of the teacher's courses that are not archived
     */
    private function openCourseIds(Teacher $teacher): array
    {
        $ids = [];
        foreach ($this->courses->findBy(['teacher' => $teacher, 'archivedAt' => null]) as $course) {
            $ids[$course->getId()->toRfc4122()] = true;
        }

        return $ids;
    }

    private function teacher(): ?Teacher
    {
        $user = $this->security->getUser();

        return $user instanceof Teacher ? $user : null;
    }

    private static function uuid(Uuid|string $id): Uuid
    {
        return $id instanceof Uuid ? $id : Uuid::fromString($id);
    }

    private static function key(Teacher $teacher, Uuid $id): string
    {
        return $teacher->getId()->toRfc4122().'|'.$id->toRfc4122();
    }
}
