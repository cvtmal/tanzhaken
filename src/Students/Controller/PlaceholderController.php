<?php

declare(strict_types=1);

namespace App\Students\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder pages for the Students routes; each owning cycle replaces its action.
 * `student_new` is declared before `student_show` so that `/students/new` is not taken for an id.
 */
final class PlaceholderController extends AbstractController
{
    #[Route('/students', name: 'student_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'student_index']);
    }

    #[Route('/students/new', name: 'student_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'student_new']);
    }

    #[Route('/students/{id}', name: 'student_show', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'student_show']);
    }

    #[Route('/students/{id}/edit', name: 'student_edit', methods: ['GET'])]
    public function edit(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'student_edit']);
    }
}
