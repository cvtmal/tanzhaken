<?php

declare(strict_types=1);

namespace App\Credits\ReadModel;

use App\Account\Entity\Teacher;
use App\Courses\Entity\LessonStatus;
use Doctrine\DBAL\Connection;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The only implementation of the balance formula (S2, S3, CRD-2): credits of non-voided grants
 * minus `present` attendances at non-cancelled lessons of the enrollment's course. Nothing is
 * stored. Every public method is one SQL query and filters on the teacher (S4), so a foreign or
 * unknown ID gives null or an empty list.
 */
final class Balances
{
    /**
     * `'present'` is a literal: Credits may not use App\Attendance (the module map). Each scalar
     * subquery is an index search (idx_credit_grant_enrollment, idx_attendance_student_status).
     */
    private const SELECT = <<<'SQL'
        SELECT e.id AS enrollment_id, e.left_at, c.id AS course_id, c.name AS course_name,
               s.id AS student_id, s.first_name, s.last_name,
               (SELECT COALESCE(SUM(g.quantity), 0) FROM credit_grant g
                  WHERE g.enrollment_id = e.id AND g.voided_at IS NULL) AS credits,
               (SELECT COUNT(*) FROM attendance a
                  INNER JOIN lesson_session l ON l.id = a.lesson_session_id
                  WHERE a.student_id = e.student_id AND l.course_id = e.course_id
                    AND a.status = 'present' AND l.status <> :cancelled) AS used
        FROM enrollment e
        INNER JOIN course c ON c.id = e.course_id
        INNER JOIN student s ON s.id = e.student_id
        WHERE c.teacher_id = :teacher
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * The balance of one of the teacher's enrollments (left or not); null when the enrollment
     * does not exist or belongs to another teacher. For a grant form or a single badge (#12).
     */
    public function forEnrollment(Teacher $teacher, Uuid $enrollmentId): ?EnrollmentBalance
    {
        $rows = $this->fetch(self::SELECT.' AND e.id = :enrollment ORDER BY e.id', $teacher, ['enrollment' => $enrollmentId]);

        return $rows[0] ?? null;
    }

    /**
     * Every enrollment of one course, left ones included (`active` false), ordered by last name,
     * first name; keyed by the enrollment id (RFC 4122) so a roster row can look up its balance (#12).
     *
     * @return array<string, EnrollmentBalance>
     */
    public function forCourse(Teacher $teacher, Uuid $courseId): array
    {
        $rows = $this->fetch(
            self::SELECT.' AND e.course_id = :course ORDER BY s.last_name, s.first_name, e.id',
            $teacher,
            ['course' => $courseId],
        );

        $byId = [];
        foreach ($rows as $balance) {
            $byId[$balance->enrollmentId->toRfc4122()] = $balance;
        }

        return $byId;
    }

    /**
     * Every enrollment of one student, left ones included, ordered by course name: the student page (#12).
     *
     * @return list<EnrollmentBalance>
     */
    public function forStudent(Teacher $teacher, Uuid $studentId): array
    {
        return $this->fetch(self::SELECT.' AND e.student_id = :student ORDER BY c.name, e.id', $teacher, ['student' => $studentId]);
    }

    /**
     * The teacher's active enrollments (not left, course and student not archived) with a balance
     * at or below zero, lowest first, then course name and last name: the dashboard list (#17).
     *
     * @return list<EnrollmentBalance>
     */
    public function renewalNeeded(Teacher $teacher): array
    {
        $sql = 'SELECT * FROM ('.self::SELECT
            .' AND e.left_at IS NULL AND c.archived_at IS NULL AND s.archived_at IS NULL) b'
            .' WHERE b.credits - b.used <= 0'
            .' ORDER BY b.credits - b.used, b.course_name, b.last_name, b.enrollment_id';

        return $this->fetch($sql, $teacher, []);
    }

    /**
     * @param array<string, Uuid> $uuids extra UUID parameters besides the teacher
     *
     * @return list<EnrollmentBalance>
     */
    private function fetch(string $sql, Teacher $teacher, array $uuids): array
    {
        $params = ['teacher' => $teacher->getId(), 'cancelled' => LessonStatus::Cancelled->value] + $uuids;
        $types = array_fill_keys(['teacher', ...array_keys($uuids)], UuidType::NAME);

        return array_map(
            $this->hydrate(...),
            $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative(),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): EnrollmentBalance
    {
        // Integers cast explicitly: MariaDB/PDO may return numeric strings.
        $credits = (int) $row['credits'];
        $used = (int) $row['used'];
        $balance = $credits - $used;

        return new EnrollmentBalance(
            Uuid::fromBinary((string) $row['enrollment_id']),
            Uuid::fromBinary((string) $row['course_id']),
            (string) $row['course_name'],
            Uuid::fromBinary((string) $row['student_id']),
            (string) $row['first_name'],
            (string) $row['last_name'],
            null === $row['left_at'],
            $credits,
            $used,
            $balance,
            RenewalState::fromBalance($balance),
        );
    }
}
