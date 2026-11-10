<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder page for the Dashboard route; its owning cycle replaces it.
 */
final class PlaceholderController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'dashboard']);
    }
}
