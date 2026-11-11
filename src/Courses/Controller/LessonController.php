<?php

declare(strict_types=1);

namespace App\Courses\Controller;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonSession;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Form\CancelLessonData;
use App\Courses\Form\CancelLessonType;
use App\Courses\Form\ConfirmActionType;
use App\Courses\Form\LessonData;
use App\Courses\Form\LessonType;
use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\LessonSessionRepository;
use App\Courses\Service\LessonScheduler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 * The lesson actions of the course page (CRS-3, CRS-4): extend the schedule, add, re-time, cancel
 * and reinstate one lesson. POST only; each answers 303 to `course_show` with a `success` or
 * `error` flash (P5), also after a race backstop, when the EntityManager is closed
 * (`05-scheduling.md` Carried forward). A foreign or unknown course, or a lesson that is not the
 * course's, answers 404 (P2). The forms are built by CourseController::show() with the same names
 * (ConfirmActionType::formName()).
 */
#[IsGranted('ROLE_TEACHER')]
final class LessonController extends AbstractController
{
    /** Local date in flash messages (design: "Thu 12 Nov"). */
    private const FLASH_DATE_PATTERN = 'EEE d MMM';

    public function __construct(
        private readonly CourseRepository $courses,
        private readonly LessonSessionRepository $lessons,
        private readonly LessonScheduler $scheduler,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/courses/{id}/extend', name: 'course_extend', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function extend(#[CurrentUser] Teacher $teacher, Uuid $id, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $form = $this->forms->createNamed(ConfirmActionType::formName('extend', $id), ConfirmActionType::class);

        return $this->handle($request, $form, $course, function () use ($teacher, $id, $course, $request): ?string {
            // Recomputed here, never posted (D4).
            $target = $this->scheduler->extensionTarget($course);
            if (null === $target) {
                if ($course->isArchived()) {
                    throw InvalidCourse::courseArchived();
                }
                $this->addFlash('error', $this->translator->trans('course.flash.nothing_to_extend', [], 'courses'));

                return null;
            }
            $count = $this->scheduler->extend($teacher, $id, $target);

            return $this->translator->trans('course.flash.extended', [
                'count' => $count,
                'date' => $this->formatDate(new \DateTimeImmutable($target->format('Y-m-d')), 'UTC', $request->getLocale()),
            ], 'courses');
        });
    }

    #[Route('/courses/{id}/lessons', name: 'course_lesson_add', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function add(#[CurrentUser] Teacher $teacher, Uuid $id, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $data = new LessonData();
        $form = $this->forms->createNamed(ConfirmActionType::formName('add_lesson', $id), LessonType::class, $data);

        return $this->handle($request, $form, $course, function () use ($teacher, $id, $data, $course, $request): string {
            $lesson = $this->scheduler->addLesson($teacher, $id, $data->localStart(), $data->durationMinutes);

            return $this->translator->trans('course.flash.lesson_added', [
                'date' => $this->lessonDate($lesson, $course, $request),
            ], 'courses');
        });
    }

    #[Route(
        '/courses/{id}/lessons/{lessonId}/reschedule',
        name: 'course_lesson_reschedule',
        requirements: ['id' => Requirement::UUID, 'lessonId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function reschedule(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $lessonId, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $lesson = $this->lesson($teacher, $course, $lessonId);
        $data = new LessonData();
        $form = $this->forms->createNamed(ConfirmActionType::formName('reschedule', $lessonId), LessonType::class, $data);

        return $this->handle($request, $form, $course, function () use ($teacher, $lessonId, $data, $lesson, $course, $request): string {
            $this->scheduler->reschedule($teacher, $lessonId, $data->localStart(), $data->durationMinutes);

            // The managed entity carries the new start.
            return $this->translator->trans('course.flash.lesson_rescheduled', [
                'date' => $this->lessonDate($lesson, $course, $request),
            ], 'courses');
        });
    }

    #[Route(
        '/courses/{id}/lessons/{lessonId}/cancel',
        name: 'course_lesson_cancel',
        requirements: ['id' => Requirement::UUID, 'lessonId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function cancel(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $lessonId, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $lesson = $this->lesson($teacher, $course, $lessonId);
        $data = new CancelLessonData();
        $form = $this->forms->createNamed(ConfirmActionType::formName('cancel', $lessonId), CancelLessonType::class, $data);

        return $this->handle($request, $form, $course, function () use ($teacher, $lessonId, $data, $lesson, $course, $request): string {
            $this->scheduler->cancel($teacher, $lessonId, $data->reason);

            return $this->translator->trans('course.flash.lesson_cancelled', [
                'date' => $this->lessonDate($lesson, $course, $request),
            ], 'courses');
        });
    }

    #[Route(
        '/courses/{id}/lessons/{lessonId}/reinstate',
        name: 'course_lesson_reinstate',
        requirements: ['id' => Requirement::UUID, 'lessonId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function reinstate(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $lessonId, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $lesson = $this->lesson($teacher, $course, $lessonId);
        $form = $this->forms->createNamed(ConfirmActionType::formName('reinstate', $lessonId), ConfirmActionType::class);

        return $this->handle($request, $form, $course, function () use ($teacher, $lessonId, $lesson, $course, $request): string {
            $this->scheduler->reinstate($teacher, $lessonId);

            return $this->translator->trans('course.flash.lesson_reinstated', [
                'date' => $this->lessonDate($lesson, $course, $request),
            ], 'courses');
        });
    }

    /**
     * Runs `$action` for a submitted, valid form and flashes its success message; flashes the
     * first form error or the refusal otherwise (P5). Always 303 to the course page.
     *
     * @param FormInterface<mixed> $form
     * @param callable(): ?string  $action returns the success message, or null when it flashed itself
     */
    private function handle(Request $request, FormInterface $form, Course $course, callable $action): Response
    {
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            $this->addFlash('error', $this->translator->trans('course.flash.invalid_request', [], 'courses'));
        } elseif (!$form->isValid()) {
            $this->addFlash('error', $this->firstError($form));
        } else {
            try {
                $message = $action();
                if (null !== $message) {
                    $this->addFlash('success', $message);
                }
            } catch (NotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidCourse $refusal) {
                // Also the unique-key race of addLesson()/reschedule(): the scheduler maps it to lessonTimeTaken().
                $this->addFlash('error', $this->translator->trans($refusal->translationKey(), $refusal->translationParameters(), 'courses'));
            }
        }

        return $this->redirectToRoute('course_show', ['id' => $course->getId()->toRfc4122()], Response::HTTP_SEE_OTHER);
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

        return $this->translator->trans('course.flash.invalid_request', [], 'courses');
    }

    private function course(Teacher $teacher, Uuid $id): Course
    {
        return $this->courses->findOwned($teacher, $id) ?? throw $this->createNotFoundException();
    }

    /**
     * The teacher's lesson, and one of this course (P2), or 404.
     */
    private function lesson(Teacher $teacher, Course $course, Uuid $lessonId): LessonSession
    {
        $lesson = $this->lessons->findOwned($teacher, $lessonId);
        if (null === $lesson || !$lesson->getCourse()->getId()->equals($course->getId())) {
            throw $this->createNotFoundException();
        }

        return $lesson;
    }

    private function lessonDate(LessonSession $lesson, Course $course, Request $request): string
    {
        return $this->formatDate($lesson->getStartsAtUtc(), $course->getTimezone(), $request->getLocale());
    }

    private function formatDate(\DateTimeImmutable $moment, string $timezone, string $locale): string
    {
        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timezone, null, self::FLASH_DATE_PATTERN);

        return (string) $formatter->format($moment);
    }
}
