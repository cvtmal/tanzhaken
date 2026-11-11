<?php

declare(strict_types=1);

namespace App\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/` has no page of its own: a logged-in teacher goes to the dashboard, anyone else to the login.
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute($this->isGranted('ROLE_TEACHER') ? 'dashboard' : 'login');
    }
}
