<?php

declare(strict_types=1);

namespace App\CheckIn\Controller;

use App\Attendance\Service\CheckInOutcome;
use App\CheckIn\Service\CheckInConfirmation;
use App\CheckIn\Service\CheckInRefusal;
use App\CheckIn\Service\CheckInThrottle;
use App\CheckIn\Service\CheckInTokens;
use App\CheckIn\Service\PublicCheckIn;
use App\CheckIn\Service\PublicRoster;
use App\Credits\ReadModel\RenewalState;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The public student check-in (#14, S8, QR-2–QR-5): the roster behind a token and its confirm step.
 *
 * No session, no cookie, no CSRF token (D-041): the path token is the capability, the confirm is
 * POST only, the enrollment must be on that window's roster, both limiters apply and a
 * `Sec-Fetch-Site: cross-site` POST is refused (403) before the limiters (plan P1). Every
 * CheckInRefusal answers the one public message with 404, so a visitor learns nothing about the
 * token. Every response passes through publicResponse() for the S8 headers.
 */
final class PublicCheckInController extends AbstractController
{
    private const ENROLLMENT_FIELD = 'enrollment';

    public function __construct(
        private readonly PublicCheckIn $checkIn,
        private readonly CheckInThrottle $throttle,
        private readonly CheckInTokens $tokens,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/c/{token}', name: 'checkin_public', methods: ['GET'])]
    public function roster(Request $request, string $token): Response
    {
        $throttled = $this->throttled($request, $token);
        if (null !== $throttled) {
            return $throttled;
        }

        $roster = $this->checkIn->roster($token);
        if ($roster instanceof CheckInRefusal) {
            return $this->refused();
        }

        return $this->rosterPage($token, $roster, false);
    }

    #[Route('/c/{token}/confirm', name: 'checkin_public_confirm', methods: ['POST'])]
    public function confirm(Request $request, string $token): Response
    {
        if ('cross-site' === $request->headers->get('Sec-Fetch-Site')) {
            return $this->message('refused', Response::HTTP_FORBIDDEN);
        }

        $throttled = $this->throttled($request, $token);
        if (null !== $throttled) {
            return $throttled;
        }

        // The lesson facts for the result page; a refusal here wins over a bad selection (plan P4).
        $roster = $this->checkIn->roster($token);
        if ($roster instanceof CheckInRefusal) {
            return $this->refused();
        }

        $enrollmentId = $request->request->all()[self::ENROLLMENT_FIELD] ?? null;
        if (!\is_string($enrollmentId) || !Uuid::isValid($enrollmentId)) {
            return $this->rosterPage($token, $roster, true);
        }

        $confirmation = $this->checkIn->confirm($token, Uuid::fromString($enrollmentId));
        if ($confirmation instanceof CheckInRefusal) {
            return $this->refused();
        }

        return match ($confirmation->outcome) {
            CheckInOutcome::Recorded => $this->resultPage('recorded', $roster, $confirmation),
            CheckInOutcome::AlreadyPresent => $this->resultPage('already', $roster, $confirmation),
            CheckInOutcome::MarkedByTeacher => $this->message('marked', Response::HTTP_OK),
        };
    }

    /**
     * The IP limiter first, then (for a well-formed token only) the token limiter, keyed by its hash.
     */
    private function throttled(Request $request, string $token): ?Response
    {
        $retryAt = $this->throttle->allowIp($request->getClientIp() ?? 'unknown');
        if (null === $retryAt && $this->tokens->isWellFormed($token)) {
            $retryAt = $this->throttle->allowToken($this->tokens->hash($token));
        }

        if (null === $retryAt) {
            return null;
        }

        $response = $this->message('too_many', Response::HTTP_TOO_MANY_REQUESTS);
        $seconds = $retryAt->getTimestamp() - $this->clock->now()->getTimestamp();
        $response->headers->set('Retry-After', (string) max(1, $seconds));

        return $response;
    }

    private function rosterPage(string $token, PublicRoster $roster, bool $invalidChoice): Response
    {
        return $this->publicResponse($this->render('checkin/roster.html.twig', [
            'token' => $token,
            'check_in' => $roster->checkIn,
            'students' => $roster->students,
            'invalid_choice' => $invalidChoice,
        ], new Response(status: $invalidChoice ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /**
     * @param 'recorded'|'already' $state
     */
    private function resultPage(string $state, PublicRoster $roster, CheckInConfirmation $confirmation): Response
    {
        return $this->publicResponse($this->render('checkin/result.html.twig', [
            'state' => $state,
            'check_in' => $roster->checkIn,
            'label' => $confirmation->label,
            'balance' => $confirmation->balance,
            'renewal' => RenewalState::fromBalance($confirmation->balance),
        ]));
    }

    private function refused(): Response
    {
        return $this->message('refused', Response::HTTP_NOT_FOUND);
    }

    /**
     * @param 'refused'|'marked'|'too_many' $kind
     */
    private function message(string $kind, int $status): Response
    {
        return $this->publicResponse($this->render('checkin/message.html.twig', ['kind' => $kind], new Response(status: $status)));
    }

    /**
     * S8 headers on every response of both actions (research Decision 8).
     */
    private function publicResponse(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
