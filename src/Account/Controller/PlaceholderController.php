<?php

declare(strict_types=1);

namespace App\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Placeholder pages for the Account routes; each owning cycle replaces its action.
 */
final class PlaceholderController extends AbstractController
{
    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'privacy']);
    }
}
