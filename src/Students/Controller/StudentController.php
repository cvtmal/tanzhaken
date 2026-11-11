<?php

declare(strict_types=1);

namespace App\Students\Controller;

use App\Account\Entity\Teacher;
use App\Account\Security\TeacherOwnsVoter;
use App\Students\Entity\Student;
use App\Students\Exception\InvalidStudent;
use App\Students\Exception\StudentNotFound;
use App\Students\Form\StudentData;
use App\Students\Form\StudentType;
use App\Students\ReadModel\StudentCourseRow;
use App\Students\ReadModel\StudentCourses;
use App\Students\ReadModel\StudentList;
use App\Students\Service\StudentDirectory;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The student directory (STU-1, STU-2): list with name search and archived filter, create, edit,
 * archive, restore, and the student page. A foreign or unknown student answers 404 at the
 * voter and again in the service (S4). `student_new` is declared before `student_show`.
 */
final class StudentController extends AbstractController
{
    public function __construct(
        private readonly StudentDirectory $directory,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/students', name: 'student_index', methods: ['GET'])]
    public function index(Request $request, #[CurrentUser] Teacher $teacher, StudentList $list): Response
    {
        $query = trim($request->query->getString('q'));
        $archived = '1' === $request->query->getString('archived');

        $students = $list->forTeacher($teacher, $query, $archived);

        return $this->render('student/index.html.twig', [
            'students' => $students,
            'count' => \count($students),
            'query' => $query,
            'archived' => $archived,
        ]);
    }

    #[Route('/students/new', name: 'student_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] Teacher $teacher): Response
    {
        $data = new StudentData();
        $form = $this->createForm(StudentType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $student = $this->directory->create($teacher, $data);
                $this->addFlash('success', $this->trans('flash.created', $student));

                return $this->redirectToRoute('student_show', ['id' => $student->getId()], Response::HTTP_SEE_OTHER);
            } catch (InvalidStudent $e) {
                $this->addFormError($form, $e);
            }
        }

        // Passing the form (not its view) makes render() answer 422 for a submitted, invalid form.
        return $this->render('student/new.html.twig', ['form' => $form]);
    }

    #[Route('/students/{id}', name: 'student_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'student', statusCode: 404)]
    public function show(#[MapEntity(id: 'id')] Student $student, #[CurrentUser] Teacher $teacher, StudentCourses $courses): Response
    {
        $rows = $courses->forStudent($teacher, $student->getId());

        return $this->render('student/show.html.twig', [
            'student' => $student,
            'teacher_timezone' => $teacher->getTimezone(),
            'active_courses' => array_values(array_filter($rows, static fn (StudentCourseRow $row): bool => $row->isActive())),
            'earlier_courses' => array_values(array_filter($rows, static fn (StudentCourseRow $row): bool => !$row->isActive())),
        ]);
    }

    #[Route('/students/{id}/edit', name: 'student_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'student', statusCode: 404)]
    public function edit(Request $request, #[MapEntity(id: 'id')] Student $student, #[CurrentUser] Teacher $teacher): Response
    {
        $data = StudentData::fromStudent($student);
        $form = $this->createForm(StudentType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->directory->update($teacher, $student->getId(), $data);
                $this->addFlash('success', $this->translator->trans('flash.updated', [], 'students'));

                return $this->redirectToRoute('student_show', ['id' => $student->getId()], Response::HTTP_SEE_OTHER);
            } catch (StudentNotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidStudent $e) {
                $this->addFormError($form, $e);
            }
        }

        return $this->render('student/edit.html.twig', ['form' => $form, 'student' => $student]);
    }

    #[Route('/students/{id}/archive', name: 'student_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'student', statusCode: 404)]
    #[IsCsrfTokenValid('student_archive')]
    public function archive(#[MapEntity(id: 'id')] Student $student, #[CurrentUser] Teacher $teacher): Response
    {
        try {
            $this->directory->archive($teacher, $student->getId());
            $this->addFlash('success', $this->trans('flash.archived', $student));
        } catch (StudentNotFound) {
            throw $this->createNotFoundException();
        } catch (InvalidStudent) {
            $this->addFlash('warning', $this->trans('flash.already_archived', $student));
        }

        return $this->redirectToRoute('student_show', ['id' => $student->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/students/{id}/restore', name: 'student_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(TeacherOwnsVoter::ATTRIBUTE, subject: 'student', statusCode: 404)]
    #[IsCsrfTokenValid('student_restore')]
    public function restore(#[MapEntity(id: 'id')] Student $student, #[CurrentUser] Teacher $teacher): Response
    {
        try {
            $this->directory->restore($teacher, $student->getId());
            $this->addFlash('success', $this->trans('flash.restored', $student));
        } catch (StudentNotFound) {
            throw $this->createNotFoundException();
        } catch (InvalidStudent) {
            $this->addFlash('warning', $this->trans('flash.not_archived', $student));
        }

        return $this->redirectToRoute('student_show', ['id' => $student->getId()], Response::HTTP_SEE_OTHER);
    }

    private function trans(string $key, Student $student): string
    {
        return $this->translator->trans($key, ['name' => $student->getFirstName().' '.$student->getLastName()], 'students');
    }

    /**
     * The form constraints mirror the entity's guards, so this is a fallback for input that
     * passes the form but not the entity; the message stays generic (S7: no English from PHP).
     */
    private function addFormError(FormInterface $form, InvalidStudent $e): void
    {
        $form->addError(new FormError($this->translator->trans('students.student.invalid', [], 'validators'), cause: $e));
    }
}
