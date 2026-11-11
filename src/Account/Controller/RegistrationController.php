<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Exception\EmailAlreadyRegistered;
use App\Account\Form\RegistrationData;
use App\Account\Form\RegistrationType;
use App\Account\Service\Registration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Registration (A2, ACC-1): one screen, then straight into the app logged in (#4 D2).
 * An invalid form or a taken email re-renders the form with 422.
 */
final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function __invoke(
        Request $request,
        Registration $registration,
        Security $security,
        TranslatorInterface $translator,
    ): Response {
        if ($this->isGranted('ROLE_TEACHER')) {
            return $this->redirectToRoute('dashboard');
        }

        $data = new RegistrationData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $teacher = $registration->register($data);
                $security->login($teacher, 'form_login', 'main');

                return $this->redirectToRoute('dashboard');
            } catch (EmailAlreadyRegistered) {
                $form->get('email')->addError(new FormError($translator->trans('register.email.taken', [], 'account')));
            }
        }

        // Passing the form (not its view) makes render() answer 422 for a submitted, invalid form.
        return $this->render('account/register.html.twig', ['form' => $form]);
    }
}
