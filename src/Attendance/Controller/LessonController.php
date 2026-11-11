<?php

declare(strict_types=1);

namespace App\Attendance\Controller;

use App\Account\Entity\Teacher;
use App\Attendance\Entity\AttendanceStatus;
use App\Attendance\Exception\AttendanceNotFound;
use App\Attendance\Exception\InvalidAttendance;
use App\Attendance\Form\MarkAttendanceData;
use App\Attendance\Form\MarkAttendanceType;
use App\Attendance\ReadModel\LessonRegister;
use App\Attendance\ReadModel\LessonRegisterEntry;
use App\Attendance\Service\AttendanceMarker;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Entity\LessonStatus;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Form\CancelLessonType;
use App\Courses\Form\ConfirmActionType;
use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\LessonSessionRepository;
use App\Courses\Service\LessonScheduler;
use App\Credits\ReadModel\Balances;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The teacher's lesson page (REG-1, REG-2, #13): the register with status, source and balance;
 * mark present / absent or clear one row with an optional reason (P1); complete the lesson (R7).
 * Cancelling posts to #10's `course_lesson_cancel` (R8, P2). A foreign or unknown course, a lesson
 * that is not the course's, or a malformed id answers 404 (S4). The POST actions answer 303 to
 * `lesson_show` with a `success` or `error` flash (P7), also after `changedConcurrently` (the
 * EntityManager is closed then).
 *
 * Template variables of `lesson/show.html.twig`:
 * - `course` Course; `lesson` LessonSession;
 * - `entries` list<LessonRegisterEntry> (the register's order: last name, first name);
 * - `mark_forms` array<string student id RFC 4122, array{form: FormView, can_mark: bool,
 *   can_clear: bool}>: one `mark_<base58 student id>` form (MarkAttendanceType, posts to
 *   `lesson_mark`) per entry that may be marked or cleared (P5). `can_mark` = lesson not cancelled
 *   and enrollment active (show Present / Absent); `can_clear` = the entry has a mark (show Clear).
 *   An entry without a key here gets no form;
 * - `complete_form` ?FormView (ConfirmActionType `complete_<base58 lesson id>`, posts to
 *   `lesson_complete`): set while the lesson is scheduled and has started (P6);
 * - `cancel_form` ?FormView (#10's CancelLessonType `cancel_<base58 lesson id>`, posts to
 *   `course_lesson_cancel`): set while the lesson is not cancelled and no entry is marked (P6);
 * - `cancel_blocked` bool: the lesson is not cancelled but has marks ("Clear the marks to cancel");
 * - `present_count` int (present entries), `roster_count` int (entries with an active enrollment);
 * - `course_slot` array{id: string, name: string, timezone: string} and `lesson_slot`
 *   array{id: string, status: string, startsAtUtc: \DateTimeImmutable} for the #16 include (R10).
 *
 * Complete and cancel are not offered on an archived course (the scheduler refuses both).
 */
#[IsGranted('ROLE_TEACHER')]
final class LessonController extends AbstractController
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly LessonSessionRepository $lessons,
        private readonly LessonRegister $register,
        private readonly Balances $balances,
        private readonly AttendanceMarker $marker,
        private readonly LessonScheduler $scheduler,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        '/courses/{id}/lessons/{lessonId}',
        name: 'lesson_show',
        requirements: ['id' => Requirement::UUID, 'lessonId' => Requirement::UUID],
        methods: ['GET'],
    )]
    public function show(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $lessonId): Response
    {
        $course = $this->course($teacher, $id);
        $lesson = $this->lesson($teacher, $course, $lessonId);
        $rows = $this->register->forLesson($teacher, $lessonId) ?? throw $this->createNotFoundException();
        $entries = LessonRegisterEntry::fromRegister($rows, $this->balances->forCourse($teacher, $id));

        $cancelled = $lesson->isCancelled();
        $lessonIds = ['id' => $id->toRfc4122(), 'lessonId' => $lessonId->toRfc4122()];
        $markForms = [];
        $presentCount = 0;
        $rosterCount = 0;
        $hasMarks = false;
        foreach ($entries as $entry) {
            $presentCount += $entry->isPresent() ? 1 : 0;
            $rosterCount += $entry->row->enrollmentActive ? 1 : 0;
            $hasMarks = $hasMarks || $entry->isMarked();

            $canMark = !$cancelled && $entry->row->enrollmentActive;
            $canClear = $entry->isMarked();
            if (!$canMark && !$canClear) {
                continue;
            }
            $studentId = $entry->row->studentId;
            $markForms[$studentId->toRfc4122()] = [
                'form' => $this->forms->createNamed(
                    ConfirmActionType::formName('mark', $studentId),
                    MarkAttendanceType::class,
                    null,
                    ['action' => $this->generateUrl('lesson_mark', $lessonIds + ['studentId' => $studentId->toRfc4122()])],
                )->createView(),
                'can_mark' => $canMark,
                'can_clear' => $canClear,
            ];
        }

        $schedulable = !$course->isArchived();
        $completable = $schedulable
            && LessonStatus::Scheduled === $lesson->getStatus()
            && $lesson->getStartsAtUtc() <= $this->clock->now();
        $cancellable = $schedulable && !$cancelled && !$hasMarks;

        return $this->render('lesson/show.html.twig', [
            'course' => $course,
            'lesson' => $lesson,
            'entries' => $entries,
            'mark_forms' => $markForms,
            'complete_form' => $completable
                ? $this->forms->createNamed(
                    ConfirmActionType::formName('complete', $lessonId),
                    ConfirmActionType::class,
                    null,
                    ['action' => $this->generateUrl('lesson_complete', $lessonIds)],
                )->createView()
                : null,
            'cancel_form' => $cancellable
                ? $this->forms->createNamed(
                    ConfirmActionType::formName('cancel', $lessonId),
                    CancelLessonType::class,
                    null,
                    ['action' => $this->generateUrl('course_lesson_cancel', $lessonIds)],
                )->createView()
                : null,
            'cancel_blocked' => $schedulable && !$cancelled && $hasMarks,
            'present_count' => $presentCount,
            'roster_count' => $rosterCount,
            'course_slot' => ['id' => $id->toRfc4122(), 'name' => $course->getName(), 'timezone' => $course->getTimezone()],
            'lesson_slot' => ['id' => $lessonId->toRfc4122(), 'status' => $lesson->getStatus()->value, 'startsAtUtc' => $lesson->getStartsAtUtc()],
        ]);
    }

    #[Route(
        '/courses/{id}/lessons/{lessonId}/attendance/{studentId}',
        name: 'lesson_mark',
        requirements: ['id' => Requirement::UUID, 'lessonId' => Requirement::UUID, 'studentId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function mark(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $lessonId, Uuid $studentId, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $this->lesson($teacher, $course, $lessonId);
        $data = new MarkAttendanceData();
        $form = $this->forms->createNamed(ConfirmActionType::formName('mark', $studentId), MarkAttendanceType::class, $data);

        return $this->handle($request, $form, $course, $lessonId, 'student-'.$studentId->toRfc4122(), function () use ($teacher, $lessonId, $studentId, $form, $data): ?string {
            // Read before the write: a cleared student who left the course is no longer on the register.
            $name = $this->displayName($teacher, $lessonId, $studentId);
            $action = match (true) {
                self::clicked($form, 'present') => 'present',
                self::clicked($form, 'absent') => 'absent',
                self::clicked($form, 'clear') => 'clear',
                default => null,
            };
            if (null === $action) {
                $this->addFlash('error', $this->translator->trans('attendance.flash.invalid_request', [], 'attendance'));

                return null;
            }

            if ('clear' === $action) {
                $this->marker->clear($teacher, $lessonId, $studentId, $data->reason);
                $key = 'attendance.flash.cleared';
            } else {
                $status = 'present' === $action ? AttendanceStatus::Present : AttendanceStatus::Absent;
                $this->marker->mark($teacher, $lessonId, $studentId, $status, $data->reason);
                $key = 'attendance.flash.marked_'.$action;
            }

            // Only a race leaves the name unknown after a successful write.
            return $this->translator->trans($key, ['name' => $name ?? ''], 'attendance');
        });
    }

    #[Route(
        '/courses/{id}/lessons/{lessonId}/complete',
        name: 'lesson_complete',
        requirements: ['id' => Requirement::UUID, 'lessonId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function complete(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $lessonId, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $this->lesson($teacher, $course, $lessonId);
        $form = $this->forms->createNamed(ConfirmActionType::formName('complete', $lessonId), ConfirmActionType::class);

        return $this->handle($request, $form, $course, $lessonId, null, function () use ($teacher, $lessonId): string {
            $this->scheduler->complete($teacher, $lessonId);

            return $this->translator->trans('attendance.flash.completed', [], 'attendance');
        });
    }

    /**
     * Runs `$action` for a submitted, valid form and flashes its success message; flashes the
     * first form error or the refusal otherwise (P7). Always 303 to the lesson page, at
     * `#$fragment` when given (R12).
     *
     * @param FormInterface<mixed> $form
     * @param callable(): ?string  $action returns the success message, or null when it flashed itself
     */
    private function handle(Request $request, FormInterface $form, Course $course, Uuid $lessonId, ?string $fragment, callable $action): Response
    {
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            $this->addFlash('error', $this->translator->trans('attendance.flash.invalid_request', [], 'attendance'));
        } elseif (!$form->isValid()) {
            $this->addFlash('error', $this->firstError($form));
        } else {
            try {
                $message = $action();
                if (null !== $message) {
                    $this->addFlash('success', $message);
                }
            } catch (AttendanceNotFound|NotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidAttendance $refusal) {
                // Also changedConcurrently(): the EntityManager is closed, the redirect is a fresh request.
                $this->addFlash('error', $this->translator->trans($refusal->translationKey(), $refusal->translationParameters(), 'attendance'));
            } catch (InvalidCourse $refusal) {
                $this->addFlash('error', $this->translator->trans($refusal->translationKey(), $refusal->translationParameters(), 'courses'));
            }
        }

        $url = $this->generateUrl('lesson_show', ['id' => $course->getId()->toRfc4122(), 'lessonId' => $lessonId->toRfc4122()]);

        return $this->redirect(null === $fragment ? $url : $url.'#'.$fragment, Response::HTTP_SEE_OTHER);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function clicked(FormInterface $form, string $button): bool
    {
        $child = $form->get($button);

        return $child instanceof ClickableInterface && $child->isClicked();
    }

    /**
     * The student's name as the register shows it ("Alex M."), or null when the student is not on it.
     */
    private function displayName(Teacher $teacher, Uuid $lessonId, Uuid $studentId): ?string
    {
        foreach (LessonRegisterEntry::fromRegister($this->register->forLesson($teacher, $lessonId) ?? [], []) as $entry) {
            if ($entry->row->studentId->equals($studentId)) {
                return $entry->displayName();
            }
        }

        return null;
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function firstError(FormInterface $form): string
    {
        foreach ($form->getErrors(true) as $error) {
            if ($error instanceof FormError) {
                return $error->getMessage();
            }
        }

        return $this->translator->trans('attendance.flash.invalid_request', [], 'attendance');
    }

    private function course(Teacher $teacher, Uuid $id): Course
    {
        return $this->courses->findOwned($teacher, $id) ?? throw $this->createNotFoundException();
    }

    /**
     * The teacher's lesson, and one of this course, or 404.
     */
    private function lesson(Teacher $teacher, Course $course, Uuid $lessonId): LessonSession
    {
        $lesson = $this->lessons->findOwned($teacher, $lessonId);
        if (null === $lesson || !$lesson->getCourse()->getId()->equals($course->getId())) {
            throw $this->createNotFoundException();
        }

        return $lesson;
    }
}
