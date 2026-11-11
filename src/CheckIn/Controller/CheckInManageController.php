<?php

declare(strict_types=1);

namespace App\CheckIn\Controller;

use App\Account\Entity\Teacher;
use App\CheckIn\Exception\CheckInNotFound;
use App\CheckIn\Exception\InvalidCheckInWindow;
use App\CheckIn\ReadModel\CheckInProgress;
use App\CheckIn\Repository\CheckInWindowRepository;
use App\CheckIn\Service\CheckInOffer;
use App\CheckIn\Service\CheckInTokenStore;
use App\CheckIn\Service\CheckInWindows;
use App\CheckIn\Service\QrCodeRenderer;
use App\Courses\Entity\LessonSession;
use App\Courses\Repository\LessonSessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The teacher's QR check-in page for one lesson (QR-1, QR-6, TEC-5): `{id}` is the lesson id.
 *
 * - `checkin_manage` GET never writes. Template `checkin/manage/show.html.twig` gets `lesson`
 *   (LessonSession), `course` (Course), `state` (open | token_missing | offer | not_yet | cancelled |
 *   archived), `status_url`, and, in state `open` only, `expires_at`, `counts` (CheckInCounts),
 *   `public_url` (absolute `checkin_public` URL) and `qr_svg` (inline SVG); null otherwise.
 * - `checkin_status` GET: counts-only JSON for polling (no names, ids or token; S4, S8).
 * - `checkin_open` / `checkin_close` POST with a session CSRF token; 303 to the page / to `lesson_show`.
 *
 * The plain token lives only in the teacher's session (CheckInTokenStore). A foreign or unknown
 * lesson answers 404 here and again in CheckInWindows (S4).
 */
#[IsGranted('ROLE_TEACHER')]
final class CheckInManageController extends AbstractController
{
    private const DOMAIN = 'checkin';

    public function __construct(
        private readonly LessonSessionRepository $lessons,
        private readonly CheckInWindowRepository $windows,
        private readonly CheckInWindows $checkIns,
        private readonly CheckInTokenStore $tokens,
        private readonly CheckInProgress $progress,
        private readonly CheckInOffer $offer,
        private readonly QrCodeRenderer $qrCodes,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/lessons/{id}/check-in', name: 'checkin_manage', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function manage(#[CurrentUser] Teacher $teacher, Uuid $id): Response
    {
        $lesson = $this->lesson($teacher, $id);
        $course = $lesson->getCourse();
        $now = $this->clock->now();
        $window = $this->windows->findActiveForLesson($lesson, $now);
        $token = null === $window ? null : $this->tokens->tokenFor($lesson->getId(), $window->getId());

        $state = match (true) {
            null !== $window => null !== $token ? 'open' : 'token_missing',
            $lesson->isCancelled() => 'cancelled',
            $course->isArchived() => 'archived',
            $this->offer->isOfferable($lesson->getStartsAtUtc(), $now) => 'offer',
            default => 'not_yet',
        };

        $expiresAt = null;
        $counts = null;
        $publicUrl = null;
        $qrSvg = null;
        if (null !== $window && null !== $token) {
            $expiresAt = $window->getExpiresAt();
            $counts = $this->progress->counts($lesson->getId(), $course->getId());
            $publicUrl = $this->generateUrl('checkin_public', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
            $qrSvg = $this->qrCodes->svg($publicUrl);
        }

        $response = $this->render('checkin/manage/show.html.twig', [
            'lesson' => $lesson,
            'course' => $course,
            'state' => $state,
            'status_url' => $this->generateUrl('checkin_status', ['id' => $lesson->getId()]),
            'expires_at' => $expiresAt,
            'counts' => $counts,
            'public_url' => $publicUrl,
            'qr_svg' => $qrSvg,
        ]);

        return $this->privateResponse($response);
    }

    #[Route('/lessons/{id}/check-in/status', name: 'checkin_status', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function status(#[CurrentUser] Teacher $teacher, Uuid $id): JsonResponse
    {
        $lesson = $this->lesson($teacher, $id);
        $now = $this->clock->now();
        $window = $this->windows->findActiveForLesson($lesson, $now);
        $counts = $this->progress->counts($lesson->getId(), $lesson->getCourse()->getId());
        $expiresAt = $window?->getExpiresAt()->setTimezone(new \DateTimeZone('UTC'));

        $response = new JsonResponse([
            'state' => null === $window ? 'closed' : 'open',
            'expiresAt' => $expiresAt?->format(\DATE_ATOM),
            'secondsLeft' => null === $expiresAt ? null : max(0, $expiresAt->getTimestamp() - $now->getTimestamp()),
            'checkedIn' => $counts->checkedIn,
            'enrolled' => $counts->enrolled,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    #[Route('/lessons/{id}/check-in/open', name: 'checkin_open', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsCsrfTokenValid('checkin_open')]
    public function open(#[CurrentUser] Teacher $teacher, Uuid $id): Response
    {
        $lesson = $this->lesson($teacher, $id);

        try {
            $this->tokens->remember($this->checkIns->open($teacher, $lesson->getId()));
            $this->addFlash('success', $this->trans('manage.flash.opened'));
        } catch (CheckInNotFound) {
            throw $this->createNotFoundException();
        } catch (InvalidCheckInWindow) {
            $this->addFlash('error', $this->trans('manage.flash.cannot_open'));
        }

        return $this->redirectToRoute('checkin_manage', ['id' => $lesson->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/lessons/{id}/check-in/close', name: 'checkin_close', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsCsrfTokenValid('checkin_close')]
    public function close(#[CurrentUser] Teacher $teacher, Uuid $id): Response
    {
        $lesson = $this->lesson($teacher, $id);

        try {
            $closed = $this->checkIns->close($teacher, $lesson->getId());
        } catch (CheckInNotFound) {
            throw $this->createNotFoundException();
        }
        $this->tokens->forget($lesson->getId());
        if ($closed) {
            $this->addFlash('success', $this->trans('manage.flash.closed'));
        } else {
            $this->addFlash('info', $this->trans('manage.flash.not_open'));
        }

        return $this->redirectToRoute('lesson_show', [
            'id' => $lesson->getCourse()->getId(),
            'lessonId' => $lesson->getId(),
        ], Response::HTTP_SEE_OTHER);
    }

    private function lesson(Teacher $teacher, Uuid $id): LessonSession
    {
        return $this->lessons->findOwned($teacher, $id) ?? throw $this->createNotFoundException();
    }

    /**
     * The page shows a live capability (the QR code and its URL): never cached, never sent as a referrer.
     */
    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], self::DOMAIN);
    }
}
