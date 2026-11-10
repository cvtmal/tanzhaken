<?php

declare(strict_types=1);

namespace App\Courses\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder pages for the Courses routes; each owning cycle replaces its action.
 * `course_new` is declared before `course_show` so that `/courses/new` is not taken for an id.
 */
final class PlaceholderController extends AbstractController
{
    #[Route('/courses', name: 'course_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'course_index']);
    }

    #[Route('/courses/new', name: 'course_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'course_new']);
    }

    #[Route('/courses/{id}', name: 'course_show', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'course_show']);
    }

    #[Route('/courses/{id}/edit', name: 'course_edit', methods: ['GET'])]
    public function edit(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'course_edit']);
    }
}
