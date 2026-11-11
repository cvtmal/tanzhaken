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
    #[Route('/forgot-password', name: 'password_forgot', methods: ['GET'])]
    public function passwordForgot(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'password_forgot']);
    }

    #[Route('/reset-password/{token}', name: 'password_reset', methods: ['GET'])]
    public function passwordReset(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'password_reset']);
    }

    #[Route('/account', name: 'account', methods: ['GET'])]
    public function account(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'account']);
    }

    #[Route('/privacy', name: 'privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('placeholder.html.twig', ['route' => 'privacy']);
    }
}
