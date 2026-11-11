<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use App\Account\Repository\TeacherRepository;
use App\Attendance\Exception\InvalidAttendance;
use App\Attendance\Service\CheckInRecorder;
use App\CheckIn\ReadModel\CheckInRoster;
use App\CheckIn\ReadModel\RosterEntry;
use App\Credits\ReadModel\Balances;
use Symfony\Component\Uid\Uuid;

/**
 * The public check-in (S8, PRD §4.5): the roster behind a token and the confirmation of one
 * enrollment. The token is the capability; there is no teacher session. Every refusal is a
 * CheckInRefusal and writes nothing. The caller (#14) applies CheckInThrottle first.
 */
final class PublicCheckIn
{
    public function __construct(
        private readonly CheckInWindows $windows,
        private readonly CheckInRoster $roster,
        private readonly RosterLabeller $labeller,
        private readonly CheckInRecorder $recorder,
        private readonly Balances $balances,
        private readonly TeacherRepository $teachers,
    ) {
    }

    public function roster(string $token): PublicRoster|CheckInRefusal
    {
        $valid = $this->windows->resolve($token);
        if ($valid instanceof CheckInRefusal) {
            return $valid;
        }

        return new PublicRoster($valid, $this->labeller->label($this->roster->forCourse($valid->courseId)));
    }

    /**
     * Records the scan of one enrollment of the token's course. An enrollment that is not on the
     * roster (unknown, another course's or teacher's, left, archived student: D-035) is
     * NotEnrolled; so is a leave or a cancellation that races the insert (research D6).
     * A teacher's mark is never overwritten (`CheckInOutcome::MarkedByTeacher`).
     */
    public function confirm(string $token, Uuid $enrollmentId): CheckInConfirmation|CheckInRefusal
    {
        $valid = $this->windows->resolve($token);
        if ($valid instanceof CheckInRefusal) {
            return $valid;
        }

        $entries = $this->roster->forCourse($valid->courseId);
        $entry = self::entry($entries, $enrollmentId);
        if (null === $entry) {
            return CheckInRefusal::NotEnrolled;
        }

        // Cannot be missing (the foreign key cascades); loaded before the insert so that this
        // refusal, too, writes nothing (P5).
        $teacher = $this->teachers->find($valid->teacherId);
        if (null === $teacher) {
            return CheckInRefusal::Unknown;
        }

        try {
            $outcome = $this->recorder->recordCheckIn($valid->lessonId, $entry->studentId);
        } catch (InvalidAttendance) {
            return CheckInRefusal::NotEnrolled;
        }

        $balance = $this->balances->forEnrollment($teacher, $enrollmentId);
        if (null === $balance) {
            throw new \LogicException('An enrollment on the roster has a balance for its teacher.');
        }

        return new CheckInConfirmation($outcome, self::label($this->labeller->label($entries), $enrollmentId), $balance->balance);
    }

    /**
     * @param list<RosterEntry> $entries
     */
    private static function entry(array $entries, Uuid $enrollmentId): ?RosterEntry
    {
        foreach ($entries as $entry) {
            if ($entry->enrollmentId->equals($enrollmentId)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<RosterLabel> $labels
     */
    private static function label(array $labels, Uuid $enrollmentId): string
    {
        foreach ($labels as $label) {
            if ($label->enrollmentId->equals($enrollmentId)) {
                return $label->label;
            }
        }

        throw new \LogicException('The labeller returns one label per roster entry.');
    }
}
