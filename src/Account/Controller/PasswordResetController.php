<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Exception\InvalidResetLink;
use App\Account\Form\ForgotPasswordData;
use App\Account\Form\ForgotPasswordType;
use App\Account\Form\ResetPasswordData;
use App\Account\Form\ResetPasswordType;
use App\Account\Service\PasswordResets;
use App\Account\Service\PasswordResetThrottle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Forgot and reset password (ACC-3). The forgot page answers the same for known, unknown and
 * email-limited addresses (R4); only the per-IP limiter is shown (429, P2). An invalid, expired or
 * used link answers 404 (P3). A reset does not log the teacher in (R3); flashes are `info` alerts,
 * since the auth layout shows no toasts (P4).
 */
final class PasswordResetController extends AbstractController
{
    #[Route('/forgot-password', name: 'password_forgot', methods: ['GET', 'POST'])]
    public function forgot(
        Request $request,
        PasswordResets $resets,
        PasswordResetThrottle $throttle,
        ClockInterface $clock,
        TranslatorInterface $translator,
    ): Response {
        if ($this->isGranted('ROLE_TEACHER')) {
            return $this->redirectToRoute('dashboard');
        }

        $data = new ForgotPasswordData();
        $form = $this->createForm(ForgotPasswordType::class, $data);

        if ($request->isMethod('POST')) {
            $retryAfter = $throttle->allowIp($request->getClientIp() ?? 'unknown');
            if (null !== $retryAfter) {
                $minutes = max(1, (int) ceil(($retryAfter->getTimestamp() - $clock->now()->getTimestamp()) / 60));

                return $this->render(
                    'account/forgot_password.html.twig',
                    ['form' => $form, 'throttled_minutes' => $minutes],
                    new Response(status: Response::HTTP_TOO_MANY_REQUESTS),
                );
            }
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $resets->request((string) $data->email);
            $this->addFlash('info', $translator->trans('forgot.sent', [], 'account'));

            return $this->redirectToRoute('password_forgot');
        }

        // Passing the form (not its view) makes render() answer 422 for a submitted, invalid form.
        return $this->render('account/forgot_password.html.twig', ['form' => $form, 'throttled_minutes' => null]);
    }

    #[Route('/reset-password/{token}', name: 'password_reset', methods: ['GET', 'POST'])]
    public function reset(
        string $token,
        Request $request,
        PasswordResets $resets,
        TranslatorInterface $translator,
    ): Response {
        if (null === $resets->findValid($token)) {
            return self::withoutLeaks($this->renderInvalid());
        }

        $data = new ResetPasswordData();
        $form = $this->createForm(ResetPasswordType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $resets->reset($token, (string) $data->password);
            } catch (InvalidResetLink) {
                return self::withoutLeaks($this->renderInvalid());
            }
            $this->addFlash('info', $translator->trans('reset.done', [], 'account'));

            return self::withoutLeaks($this->redirectToRoute('login'));
        }

        return self::withoutLeaks($this->render('account/reset_password.html.twig', ['form' => $form]));
    }

    private function renderInvalid(): Response
    {
        return $this->render('account/reset_password_invalid.html.twig', [], new Response(status: Response::HTTP_NOT_FOUND));
    }

    /**
     * The token is in the URL (R7): no Referer leaves the page, no crawler indexes it, no cache keeps it.
     */
    private static function withoutLeaks(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
