<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Entity\Teacher;
use App\Account\Exception\InvalidTeacher;
use App\Account\Form\ChangePasswordData;
use App\Account\Form\ChangePasswordType;
use App\Account\Form\DeleteAccountData;
use App\Account\Form\DeleteAccountType;
use App\Account\Form\ProfileData;
use App\Account\Form\ProfileType;
use App\Account\Service\AccountDeletion;
use App\Account\Service\AccountSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The account page (ACC-4): a profile card (name, time zone) and a password card, each posting to
 * its own route. An invalid card re-renders the page with 422; the other card is a fresh form.
 * A third card deletes the account (ACC-6, #18): password confirmation, then every row of the
 * teacher goes, the session ends and the teacher lands on /login with an info alert.
 */
final class AccountController extends AbstractController
{
    #[Route('/account', name: 'account', methods: ['GET'])]
    public function show(): Response
    {
        return $this->renderPage($this->teacher());
    }

    #[Route('/account/profile', name: 'account_profile', methods: ['POST'])]
    public function profile(Request $request, AccountSettings $settings, TranslatorInterface $translator): Response
    {
        $teacher = $this->teacher();
        $data = ProfileData::fromTeacher($teacher);
        $form = $this->profileForm($data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $settings->updateProfile($teacher, $data);
                $this->addFlash('success', $translator->trans('settings.profile.saved', [], 'account'));

                return $this->redirectToRoute('account');
            } catch (InvalidTeacher) {
                $form->get('timezone')->addError(new FormError($translator->trans('account.settings.timezone.invalid', [], 'validators')));
            }
        }

        return $this->renderPage($teacher, profile: $form);
    }

    #[Route('/account/password', name: 'account_password', methods: ['POST'])]
    public function password(Request $request, AccountSettings $settings, TranslatorInterface $translator): Response
    {
        $teacher = $this->teacher();
        $data = new ChangePasswordData();
        $form = $this->passwordForm($data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $settings->changePassword($teacher, (string) $data->newPassword);
            $this->addFlash('success', $translator->trans('settings.password.changed', [], 'account'));

            return $this->redirectToRoute('account');
        }

        return $this->renderPage($teacher, password: $form);
    }

    #[Route('/account/delete', name: 'account_delete', methods: ['POST'])]
    public function delete(Request $request, AccountDeletion $deletion, Security $security, TranslatorInterface $translator): Response
    {
        $teacher = $this->teacher();
        $form = $this->deleteForm(new DeleteAccountData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $deletion->delete($teacher);
            // The form already checked its CSRF token; logout() would check the `logout` one.
            $security->logout(false);
            $this->addFlash('info', $translator->trans('delete.done', [], 'account'));

            return $this->redirectToRoute('login');
        }

        return $this->renderPage($teacher, delete: $form);
    }

    /**
     * Passing the forms (not their views) makes render() answer 422 when a submitted one is invalid.
     */
    private function renderPage(Teacher $teacher, ?FormInterface $profile = null, ?FormInterface $password = null, ?FormInterface $delete = null): Response
    {
        return $this->render('account/show.html.twig', [
            'teacher' => $teacher,
            'profile_form' => $profile ?? $this->profileForm(ProfileData::fromTeacher($teacher)),
            'password_form' => $password ?? $this->passwordForm(new ChangePasswordData()),
            'delete_form' => $delete ?? $this->deleteForm(new DeleteAccountData()),
        ]);
    }

    private function profileForm(ProfileData $data): FormInterface
    {
        return $this->createForm(ProfileType::class, $data, ['action' => $this->generateUrl('account_profile')]);
    }

    private function passwordForm(ChangePasswordData $data): FormInterface
    {
        return $this->createForm(ChangePasswordType::class, $data, ['action' => $this->generateUrl('account_password')]);
    }

    private function deleteForm(DeleteAccountData $data): FormInterface
    {
        return $this->createForm(DeleteAccountType::class, $data, ['action' => $this->generateUrl('account_delete')]);
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw new \LogicException('The firewall lets only a logged-in teacher reach /account.');
        }

        return $user;
    }
}
