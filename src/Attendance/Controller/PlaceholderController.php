<?php

declare(strict_types=1);

namespace App\Attendance\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder pages for the Attendance routes; each owning cycle replaces its action.
 */
final class PlaceholderController extends AbstractController
{
    #[Route('/courses/{id}/lessons/{lessonId}', name: 'lesson_show', methods: ['GET'])]
    public function lessonShow(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'lesson_show']);
    }
}
