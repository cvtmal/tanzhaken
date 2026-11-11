<?php

declare(strict_types=1);

namespace App\Attendance\Controller;

use App\Account\Entity\Teacher;
use App\Attendance\Service\CourseRegisterCsv;
use App\Courses\Repository\CourseRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * `course_export` GET `/courses/{id}/export.csv` (#15, REG-6): the course register as a UTF-8 CSV
 * with a BOM, as an attachment that is never cached (D6). A foreign, unknown or malformed id
 * answers 404 (S4). Archived courses export too (D7).
 * - Lessons (D1): not cancelled, started by now or already carrying a mark; chronological.
 * - Students (D2): anyone with a mark at the lesson, plus active enrolments that joined before it ended.
 */
#[IsGranted('ROLE_TEACHER')]
final class CourseExportController extends AbstractController
{
    #[Route('/courses/{id}/export.csv', name: 'course_export', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(#[CurrentUser] Teacher $teacher, Uuid $id, CourseRepository $courses, CourseRegisterCsv $csv): Response
    {
        $course = $courses->findOwned($teacher, $id) ?? throw $this->createNotFoundException();

        return new Response($csv->build($teacher, $course), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $csv->filename($course)),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
