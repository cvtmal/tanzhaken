<?php

declare(strict_types=1);

namespace App\Courses\Service;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Repository\CourseRepository;
use App\Shared\Audit\ActorType;
use App\Shared\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creates, edits and archives a teacher's courses (CRS-1). A new course gets its first lessons
 * from LessonScheduler::generateInitial() in the same transaction (D1). A template edit leaves
 * the existing lessons alone; it applies to later extensions (D2). The course's time zone is the
 * teacher's (D3).
 *
 * Every refusal (NotFound → 404, InvalidCourse → form error) is thrown before the transaction
 * opens, so the EntityManager stays open for a form re-render (S2).
 */
final class CourseCatalog
{
    private const ENTITY_TYPE = 'course';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CourseRepository $courses,
        private readonly LessonScheduler $scheduler,
        private readonly AuditLogger $audit,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Creates a course in the teacher's time zone and generates its first lessons.
     *
     * @throws InvalidCourse the entity refuses a field
     */
    public function create(
        Teacher $teacher,
        string $name,
        int $weekday,
        \DateTimeImmutable $localStartTime,
        int $durationMinutes,
        \DateTimeImmutable $startsOn,
        ?\DateTimeImmutable $endsOn,
        ?string $location,
        ?string $description,
    ): Course {
        // The entity validates every field here, before the transaction.
        $course = new Course(
            $teacher,
            $name,
            $teacher->getTimezone(),
            $weekday,
            $localStartTime,
            $durationMinutes,
            $startsOn,
            $this->clock->now(),
            $endsOn,
            $description,
            $location,
        );

        $this->em->wrapInTransaction(function () use ($teacher, $course): void {
            $this->em->persist($course);
            $this->em->flush();
            $this->audit->record($teacher->getId(), ActorType::Teacher, 'course.created', self::ENTITY_TYPE, $course->getId(), [
                'name' => $course->getName(),
            ]);
            $this->scheduler->generateInitial($course);
        });

        return $course;
    }

    /**
     * Changes a course's details and weekly template (its time zone stays). Existing lessons are
     * not touched (D2). Audits the names of the changed fields; nothing when nothing changed.
     *
     * @throws NotFound      the course is unknown or another teacher's
     * @throws InvalidCourse the course is archived, or the entity refuses a field
     */
    public function update(
        Teacher $teacher,
        Uuid $courseId,
        string $name,
        int $weekday,
        \DateTimeImmutable $localStartTime,
        int $durationMinutes,
        \DateTimeImmutable $startsOn,
        ?\DateTimeImmutable $endsOn,
        ?string $location,
        ?string $description,
    ): void {
        $course = $this->courses->findOwned($teacher, $courseId) ?? throw NotFound::course($courseId);
        if ($course->isArchived()) {
            throw InvalidCourse::courseArchived();
        }

        $before = self::snapshot($course);
        try {
            $course->changeDetails($name, $description, $location);
            $course->changeSchedule($course->getTimezone(), $weekday, $localStartTime, $durationMinutes, $startsOn, $endsOn);
        } catch (InvalidCourse $refusal) {
            // changeDetails() may have applied before changeSchedule() refused: nothing half-changed
            // may reach a later flush.
            $this->em->refresh($course);

            throw $refusal;
        }

        // Same keys on both sides, so the diff holds exactly the changed fields, in form order.
        $changed = array_keys(array_diff_assoc(self::snapshot($course), $before));
        if ([] === $changed) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($teacher, $course, $changed): void {
            $this->audit->record($teacher->getId(), ActorType::Teacher, 'course.updated', self::ENTITY_TYPE, $course->getId(), [
                'changed' => $changed,
            ]);
        });
    }

    /**
     * Archives a course: it leaves the course list, its page stays read-only (D8).
     *
     * @throws NotFound      the course is unknown or another teacher's
     * @throws InvalidCourse the course is already archived
     */
    public function archive(Teacher $teacher, Uuid $courseId): void
    {
        $course = $this->courses->findOwned($teacher, $courseId) ?? throw NotFound::course($courseId);
        if ($course->isArchived()) {
            throw InvalidCourse::alreadyArchived();
        }
        $now = $this->clock->now();

        $this->em->wrapInTransaction(function () use ($teacher, $course, $now): void {
            $course->archive($now);
            $this->audit->record($teacher->getId(), ActorType::Teacher, 'course.archived', self::ENTITY_TYPE, $course->getId());
        });
    }

    /**
     * The editable fields as comparable strings, in form order.
     *
     * @return array<string, string>
     */
    private static function snapshot(Course $course): array
    {
        return [
            'name' => $course->getName(),
            'weekday' => (string) $course->getWeekday(),
            'local_start_time' => $course->getLocalStartTime()->format('H:i:s'),
            'duration_minutes' => (string) $course->getDurationMinutes(),
            'location' => $course->getLocation() ?? '',
            'starts_on' => $course->getStartsOn()->format('Y-m-d'),
            'ends_on' => $course->getEndsOn()?->format('Y-m-d') ?? '',
            'description' => $course->getDescription() ?? '',
        ];
    }
}
