<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Form\LoginType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Login (A1/A3) and logout (ACC-2). The firewall's form_login handles the POST to /login and
 * the logout listener the POST to /logout; these actions only render the page.
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils, FormFactoryInterface $forms): Response
    {
        if ($this->isGranted('ROLE_TEACHER')) {
            return $this->redirectToRoute('dashboard');
        }

        $form = $forms->createNamed('', LoginType::class, ['_username' => $authenticationUtils->getLastUsername()]);

        return $this->render('account/login.html.twig', [
            'form' => $form->createView(),
            'error' => $this->errorMessage($authenticationUtils->getLastAuthenticationError()),
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('The firewall intercepts POST /logout before this action runs.');
    }

    /**
     * Our own wording for a failed login (#4 D6): never Symfony's `security`-domain text.
     *
     * @return array{key: string, parameters: array<string, int>}|null
     */
    private function errorMessage(?AuthenticationException $error): ?array
    {
        if (null === $error) {
            return null;
        }

        if ($error instanceof TooManyLoginAttemptsAuthenticationException) {
            $minutes = $error->getMessageData()['%minutes%'] ?? 1;

            return ['key' => 'login.error.throttled', 'parameters' => ['minutes' => (int) $minutes]];
        }

        if ($error instanceof InvalidCsrfTokenException) {
            return ['key' => 'login.error.expired', 'parameters' => []];
        }

        return ['key' => 'login.error.invalid', 'parameters' => []];
    }
}
