<?php

declare(strict_types=1);

namespace App\CheckIn\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder pages for the CheckIn routes; each owning cycle replaces its action.
 */
final class PlaceholderController extends AbstractController
{
    #[Route('/lessons/{id}/check-in', name: 'checkin_manage', methods: ['GET'])]
    public function manage(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'checkin_manage']);
    }

    #[Route('/lessons/{id}/check-in/status', name: 'checkin_status', methods: ['GET'])]
    public function status(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'checkin_status']);
    }

    #[Route('/c/{token}', name: 'checkin_public', methods: ['GET'])]
    public function public(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'checkin_public']);
    }
}
