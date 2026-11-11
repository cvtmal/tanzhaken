<?php

declare(strict_types=1);

namespace App\Courses\Controller;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\LessonStatus;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Form\CancelLessonType;
use App\Courses\Form\ConfirmActionType;
use App\Courses\Form\CourseData;
use App\Courses\Form\CourseType;
use App\Courses\Form\EnrollType;
use App\Courses\Form\LabelData;
use App\Courses\Form\LabelType;
use App\Courses\Form\LessonData;
use App\Courses\Form\LessonType;
use App\Courses\ReadModel\CourseLessons;
use App\Courses\ReadModel\CourseOverview;
use App\Courses\ReadModel\CourseRoster;
use App\Courses\ReadModel\EnrollableStudents;
use App\Courses\Repository\CourseRepository;
use App\Courses\Service\CourseCatalog;
use App\Courses\Service\LessonScheduler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The course pages (CRS-1, CRS-5): list, create, edit, archive, and the course page with its
 * lessons and roster. A foreign or unknown ID answers 404 (P2, S4); `course_new` is declared
 * before `course_show` so `/courses/new` is not read as an id.
 *
 * Template variables (Phase 5 builds on these):
 * - `course/index.html.twig`: `cards` list<CourseCard>.
 * - `course/new.html.twig`: `form` (CourseType), `last_location` ?string (the prefill, for the
 *   "Filled in from your last course" helper).
 * - `course/edit.html.twig`: `form` (CourseType), `course` Course, `archive_form` FormView
 *   (ConfirmActionType, posts to `course_archive`).
 * - `course/show.html.twig`: `course` Course; `course_slot` array{id: string, name: string,
 *   timezone: string} (for the #12/#15 slots); `lessons` list<LessonRow> (chronological);
 *   `next_lesson` / `previous_lesson` ?LessonRow; `today` string (Y-m-d in the course zone, from the
 *   clock, for "Today, 7:00 PM"); `extension_target` ?\DateTimeImmutable (local
 *   date, only `Y-m-d` counts); `roster` list<RosterRow>; `enrollable_students` list<StudentOption>;
 *   and the forms, all null / empty when the course is archived (D8):
 *   `extend_form` ?FormView (null also when nothing is left to add), `add_lesson_form` ?FormView,
 *   `lesson_forms` array<string lesson id RFC 4122, array{reschedule?: FormView, cancel?: FormView,
 *   reinstate?: FormView}> (reschedule + cancel for a non-cancelled lesson, reinstate for a
 *   cancelled one), `enroll_form` ?FormView (null also when no student is enrollable),
 *   `roster_forms` array<string enrollment id RFC 4122, array{label: FormView, remove: FormView}>.
 *
 * Flashes: `success` / `error`, translated from the `courses` domain.
 */
#[IsGranted('ROLE_TEACHER')]
final class CourseController extends AbstractController
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseCatalog $catalog,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/courses', name: 'course_index', methods: ['GET'])]
    public function index(#[CurrentUser] Teacher $teacher, CourseOverview $overview): Response
    {
        return $this->render('course/index.html.twig', [
            'cards' => $overview->forTeacher($teacher),
        ]);
    }

    #[Route('/courses/new', name: 'course_new', methods: ['GET', 'POST'])]
    public function new(#[CurrentUser] Teacher $teacher, Request $request, CourseOverview $overview): Response
    {
        $lastLocation = $overview->lastLocation($teacher);
        $data = new CourseData();
        $data->location = $lastLocation;
        $form = $this->createForm(CourseType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $course = $this->catalog->create(
                    $teacher,
                    (string) $data->name,
                    (int) $data->weekday,
                    $data->localStartTime ?? throw new \LogicException('Validated: start time.'),
                    (int) $data->durationMinutes,
                    $data->startsOn ?? throw new \LogicException('Validated: first lesson.'),
                    $data->endsOn,
                    $data->location,
                    $data->description,
                );
                $this->addFlash('success', $this->translator->trans('course.flash.created', ['name' => $course->getName()], 'courses'));

                return $this->redirectToRoute('course_show', ['id' => $course->getId()->toRfc4122()], Response::HTTP_SEE_OTHER);
            } catch (InvalidCourse $refusal) {
                $form->addError(new FormError($this->refusalMessage($refusal)));
            }
        }

        // Passing the form (not its view) makes render() answer 422 for a submitted, invalid form.
        return $this->render('course/new.html.twig', [
            'form' => $form,
            'last_location' => $lastLocation,
        ]);
    }

    #[Route('/courses/{id}', name: 'course_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function show(
        #[CurrentUser] Teacher $teacher,
        Uuid $id,
        CourseLessons $courseLessons,
        CourseRoster $courseRoster,
        EnrollableStudents $enrollableStudents,
        LessonScheduler $scheduler,
        ClockInterface $clock,
    ): Response {
        $course = $this->course($teacher, $id);
        $lessons = $courseLessons->forCourse($teacher, $id);
        $roster = $courseRoster->forCourse($teacher, $id);
        $now = $clock->now();
        $active = !$course->isArchived();
        $students = $active ? $enrollableStudents->forCourse($teacher, $id) : [];
        $extensionTarget = $active ? $scheduler->extensionTarget($course) : null;

        $lessonForms = [];
        $rosterForms = [];
        if ($active) {
            foreach ($lessons as $lesson) {
                $key = $lesson->id->toRfc4122();
                $routeIds = ['id' => $id->toRfc4122(), 'lessonId' => $key];
                $lessonForms[$key] = LessonStatus::Cancelled === $lesson->status
                    ? ['reinstate' => $this->named('reinstate', $lesson->id, ConfirmActionType::class, null, 'course_lesson_reinstate', $routeIds)]
                    : [
                        'reschedule' => $this->named('reschedule', $lesson->id, LessonType::class, LessonData::fromLesson($lesson, $course->getTimezone()), 'course_lesson_reschedule', $routeIds),
                        'cancel' => $this->named('cancel', $lesson->id, CancelLessonType::class, null, 'course_lesson_cancel', $routeIds),
                    ];
            }
            foreach ($roster as $row) {
                $key = $row->enrollmentId->toRfc4122();
                $routeIds = ['id' => $id->toRfc4122(), 'enrollmentId' => $key];
                $label = new LabelData();
                $label->label = $row->label;
                $rosterForms[$key] = [
                    'label' => $this->named('label', $row->enrollmentId, LabelType::class, $label, 'course_enrollment_label', $routeIds),
                    'remove' => $this->named('remove', $row->enrollmentId, ConfirmActionType::class, null, 'course_enrollment_remove', $routeIds),
                ];
            }
        }
        $courseIds = ['id' => $id->toRfc4122()];

        return $this->render('course/show.html.twig', [
            'course' => $course,
            'course_slot' => ['id' => $id->toRfc4122(), 'name' => $course->getName(), 'timezone' => $course->getTimezone()],
            'lessons' => $lessons,
            'next_lesson' => CourseLessons::next($lessons, $now),
            'previous_lesson' => CourseLessons::previous($lessons, $now),
            'today' => $now->setTimezone(new \DateTimeZone($course->getTimezone()))->format('Y-m-d'),
            'extension_target' => $extensionTarget,
            'roster' => $roster,
            'enrollable_students' => $students,
            'extend_form' => null !== $extensionTarget
                ? $this->named('extend', $id, ConfirmActionType::class, null, 'course_extend', $courseIds)
                : null,
            'add_lesson_form' => $active
                ? $this->named('add_lesson', $id, LessonType::class, null, 'course_lesson_add', $courseIds)
                : null,
            'lesson_forms' => $lessonForms,
            'enroll_form' => [] !== $students
                ? $this->named('enroll', $id, EnrollType::class, null, 'course_enroll', $courseIds, ['students' => $students])
                : null,
            'roster_forms' => $rosterForms,
        ]);
    }

    #[Route('/courses/{id}/edit', name: 'course_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(#[CurrentUser] Teacher $teacher, Uuid $id, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        if ($course->isArchived()) {
            $this->addFlash('error', $this->translator->trans('course.error.course_archived', [], 'courses'));

            return $this->redirectToRoute('course_show', ['id' => $id->toRfc4122()], Response::HTTP_SEE_OTHER);
        }

        $data = CourseData::fromCourse($course);
        $form = $this->createForm(CourseType::class, $data, ['current_duration' => $course->getDurationMinutes()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->catalog->update(
                    $teacher,
                    $id,
                    (string) $data->name,
                    (int) $data->weekday,
                    $data->localStartTime ?? throw new \LogicException('Validated: start time.'),
                    (int) $data->durationMinutes,
                    $data->startsOn ?? throw new \LogicException('Validated: first lesson.'),
                    $data->endsOn,
                    $data->location,
                    $data->description,
                );
                $this->addFlash('success', $this->translator->trans('course.flash.updated', ['name' => $course->getName()], 'courses'));

                return $this->redirectToRoute('course_show', ['id' => $id->toRfc4122()], Response::HTTP_SEE_OTHER);
            } catch (NotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidCourse $refusal) {
                $form->addError(new FormError($this->refusalMessage($refusal)));
            }
        }

        return $this->render('course/edit.html.twig', [
            'form' => $form,
            'course' => $course,
            'archive_form' => $this->named('archive', $id, ConfirmActionType::class, null, 'course_archive', ['id' => $id->toRfc4122()]),
        ]);
    }

    #[Route('/courses/{id}/archive', name: 'course_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archive(#[CurrentUser] Teacher $teacher, Uuid $id, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $form = $this->forms->createNamed(ConfirmActionType::formName('archive', $id), ConfirmActionType::class);
        $form->handleRequest($request);

        if (null !== ($error = $this->formError($form))) {
            $this->addFlash('error', $error);
        } else {
            try {
                $this->catalog->archive($teacher, $id);
                $this->addFlash('success', $this->translator->trans('course.flash.archived', ['name' => $course->getName()], 'courses'));

                return $this->redirectToRoute('course_index', [], Response::HTTP_SEE_OTHER);
            } catch (NotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidCourse $refusal) {
                $this->addFlash('error', $this->refusalMessage($refusal));
            }
        }

        return $this->redirectToRoute('course_show', ['id' => $id->toRfc4122()], Response::HTTP_SEE_OTHER);
    }

    /**
     * The teacher's course or 404 (P2).
     */
    private function course(Teacher $teacher, Uuid $id): Course
    {
        return $this->courses->findOwned($teacher, $id) ?? throw $this->createNotFoundException();
    }

    /**
     * A named form of the course page posting to `$route` (P3/P4).
     *
     * @param class-string<\Symfony\Component\Form\FormTypeInterface> $type
     * @param array<string, string>                                   $routeIds
     * @param array<string, mixed>                                    $options
     */
    private function named(string $action, Uuid $id, string $type, mixed $data, string $route, array $routeIds, array $options = []): FormView
    {
        return $this->forms
            ->createNamed(ConfirmActionType::formName($action, $id), $type, $data, ['action' => $this->generateUrl($route, $routeIds)] + $options)
            ->createView();
    }

    /**
     * Null for a submitted, valid form; else the message for the error flash (P5).
     *
     * @param FormInterface<mixed> $form
     */
    private function formError(FormInterface $form): ?string
    {
        if (!$form->isSubmitted()) {
            return $this->translator->trans('course.flash.invalid_request', [], 'courses');
        }
        if ($form->isValid()) {
            return null;
        }
        foreach ($form->getErrors(true) as $error) {
            if ($error instanceof FormError) {
                return $error->getMessage();
            }
        }

        return $this->translator->trans('course.flash.invalid_request', [], 'courses');
    }

    private function refusalMessage(InvalidCourse $refusal): string
    {
        return $this->translator->trans($refusal->translationKey(), $refusal->translationParameters(), 'courses');
    }
}
