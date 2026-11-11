<?php

declare(strict_types=1);

namespace App\Courses\Controller;

use App\Account\Entity\Teacher;
use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Courses\Exception\InvalidCourse;
use App\Courses\Exception\NotFound;
use App\Courses\Form\ConfirmActionType;
use App\Courses\Form\EnrollData;
use App\Courses\Form\EnrollType;
use App\Courses\Form\LabelData;
use App\Courses\Form\LabelType;
use App\Courses\ReadModel\EnrollableStudents;
use App\Courses\Repository\CourseRepository;
use App\Courses\Repository\EnrollmentRepository;
use App\Courses\Service\Roster;
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
 * The roster actions of the course page (STU-3): enrol an existing student, remove one, change a
 * check-in label. POST only; each answers 303 to `course_show` with a `success` or `error` flash
 * (P5). A foreign or unknown course, or an enrollment that is not the course's, answers 404 (P2).
 * The forms are built by CourseController::show() with the same names
 * (ConfirmActionType::formName()).
 */
#[IsGranted('ROLE_TEACHER')]
final class EnrollmentController extends AbstractController
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly EnrollmentRepository $enrollments,
        private readonly Roster $roster,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/courses/{id}/enrollments', name: 'course_enroll', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function enroll(#[CurrentUser] Teacher $teacher, Uuid $id, Request $request, EnrollableStudents $enrollableStudents): Response
    {
        $course = $this->course($teacher, $id);
        $data = new EnrollData();
        // The same choices as the page offered, so only an enrollable student is a valid choice (D-035).
        $form = $this->forms->createNamed(ConfirmActionType::formName('enroll', $id), EnrollType::class, $data, [
            'students' => $enrollableStudents->forCourse($teacher, $id),
        ]);

        return $this->handle($request, $form, $course, function () use ($teacher, $id, $data): string {
            $enrollment = $this->roster->enroll($teacher, $id, Uuid::fromString((string) $data->student), $data->label);

            return $this->translator->trans('course.flash.enrolled', $this->studentName($enrollment), 'courses');
        });
    }

    #[Route(
        '/courses/{id}/enrollments/{enrollmentId}/remove',
        name: 'course_enrollment_remove',
        requirements: ['id' => Requirement::UUID, 'enrollmentId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function remove(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $enrollmentId, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $enrollment = $this->enrollment($teacher, $course, $enrollmentId);
        $form = $this->forms->createNamed(ConfirmActionType::formName('remove', $enrollmentId), ConfirmActionType::class);

        return $this->handle($request, $form, $course, function () use ($teacher, $enrollmentId, $enrollment): string {
            $this->roster->remove($teacher, $enrollmentId);

            return $this->translator->trans('course.flash.removed', $this->studentName($enrollment), 'courses');
        });
    }

    #[Route(
        '/courses/{id}/enrollments/{enrollmentId}/label',
        name: 'course_enrollment_label',
        requirements: ['id' => Requirement::UUID, 'enrollmentId' => Requirement::UUID],
        methods: ['POST'],
    )]
    public function label(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $enrollmentId, Request $request): Response
    {
        $course = $this->course($teacher, $id);
        $enrollment = $this->enrollment($teacher, $course, $enrollmentId);
        $data = new LabelData();
        $form = $this->forms->createNamed(ConfirmActionType::formName('label', $enrollmentId), LabelType::class, $data);

        return $this->handle($request, $form, $course, function () use ($teacher, $enrollmentId, $enrollment, $data): string {
            $this->roster->changeLabel($teacher, $enrollmentId, $data->label);

            return $this->translator->trans('course.flash.label_saved', $this->studentName($enrollment), 'courses');
        });
    }

    /**
     * Runs `$action` for a submitted, valid form and flashes its success message; flashes the
     * first form error or the refusal otherwise (P5). Always 303 to the course page.
     *
     * @param FormInterface<mixed> $form
     * @param callable(): string   $action returns the success message
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
                $this->addFlash('success', $action());
            } catch (NotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidCourse $refusal) {
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
     * The teacher's enrollment, and one of this course (P2), or 404.
     */
    private function enrollment(Teacher $teacher, Course $course, Uuid $enrollmentId): Enrollment
    {
        $enrollment = $this->enrollments->findOwned($teacher, $enrollmentId);
        if (null === $enrollment || !$enrollment->getCourse()->getId()->equals($course->getId())) {
            throw $this->createNotFoundException();
        }

        return $enrollment;
    }

    /**
     * @return array{first_name: string, last_name: string}
     */
    private function studentName(Enrollment $enrollment): array
    {
        return [
            'first_name' => $enrollment->getStudent()->getFirstName(),
            'last_name' => $enrollment->getStudent()->getLastName(),
        ];
    }
}
