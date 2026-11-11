<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Account\Entity\Teacher;
use App\Credits\ReadModel\Balances;
use App\Dashboard\ReadModel\TodaysLessons;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "Today" page (#17, UI-2): today's and upcoming lessons, the hero check-in link and the
 * renewal alerts (#6). Read-only: no POST on this page (P7).
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TodaysLessons $lessons,
        private readonly Balances $balances,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_TEACHER')]
    public function index(#[CurrentUser] Teacher $teacher): Response
    {
        $now = $this->clock->now();

        return $this->render('dashboard/index.html.twig', [
            'board' => $this->lessons->forTeacher($teacher),
            'renewals' => $this->balances->renewalNeeded($teacher),
            'teacher_timezone' => $teacher->getTimezone(),
            'greeting' => self::greeting($now, $teacher->getTimezone()),
            'now' => $now,
        ]);
    }

    /**
     * `morning` before 12:00, `afternoon` before 18:00, else `evening`, in the teacher's zone (P8).
     */
    public static function greeting(\DateTimeImmutable $now, string $timezone): string
    {
        $hour = (int) $now->setTimezone(new \DateTimeZone($timezone))->format('G');

        return match (true) {
            $hour < 12 => 'morning',
            $hour < 18 => 'afternoon',
            default => 'evening',
        };
    }
}
