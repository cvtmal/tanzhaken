<?php

declare(strict_types=1);

namespace App\Credits\Controller;

use App\Account\Entity\Teacher;
use App\Courses\Repository\CourseRepository;
use App\Credits\Entity\CreditGrant;
use App\Credits\Exception\CreditNotFound;
use App\Credits\Exception\InvalidCreditGrant;
use App\Credits\Form\CorrectData;
use App\Credits\Form\CorrectType;
use App\Credits\Form\GrantData;
use App\Credits\Form\GrantType;
use App\Credits\Form\VoidData;
use App\Credits\Form\VoidType;
use App\Credits\ReadModel\Balances;
use App\Credits\ReadModel\EnrollmentBalance;
use App\Credits\Repository\CreditGrantRepository;
use App\Credits\Service\CreditLedger;
use App\Students\Repository\StudentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The credit pages (CRD-1, CRD-5, PD1, PD2): add credits to an enrollment, void a grant, correct a
 * grant (void + new grant). GET shows the page, POST writes through CreditLedger and answers 303
 * with a `success` flash to the return target (PD4: `?from=course|student|dashboard`, carried as
 * the hidden `from` field; any other value gives the default). A submitted, invalid form or a
 * ledger refusal re-renders the page with 422 and the error on the form. A foreign, unknown or
 * mismatched id answers 404 (S4), and so does an inactive enrollment (left, course or student
 * archived) on the grant page and a voided grant on the void and correct pages (PD9).
 *
 * Template variables (Phase 3 builds on these):
 * - `credit/grant.html.twig`: `form` (GrantType: `amount` expanded radios `10`/`5`/`other`,
 *   `otherAmount`, `date`, `note`, hidden `from`); `balance` EnrollmentBalance (before the grant:
 *   names, `courseName`, `balance`, `state`); `course` Course; `after` int (balance + the chosen
 *   quantity, 10 by default); `back_url` string (the return target's page); `from` string (the
 *   ReturnTarget value).
 * - `credit/void.html.twig`: `form` (VoidType: `reason`, hidden `from`); `grant` CreditGrant;
 *   `balance` EnrollmentBalance (current, with this grant); `after` int (balance − the grant's
 *   quantity); `course` Course; `student` Student; `back_url` string; `from` string.
 * - `credit/correct.html.twig`: `form` (CorrectType: the GrantType fields prefilled from the
 *   grant, plus `reason`); `grant` CreditGrant; `balance` EnrollmentBalance (current); `course`
 *   Course; `student` Student; `back_url` string; `from` string.
 *
 * Dates: the forms' `date` is the local day in the teacher's zone (`app.user.timezone`); print a
 * grant's `grantedAt` in that zone. Flashes: `success`, translated from the `credits` domain.
 */
#[IsGranted('ROLE_TEACHER')]
final class CreditController extends AbstractController
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly Balances $balances,
        private readonly CreditGrantRepository $grants,
        private readonly CourseRepository $courses,
        private readonly StudentRepository $students,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        '/courses/{id}/enrollments/{enrollmentId}/credits',
        name: 'credit_grant',
        requirements: ['id' => Requirement::UUID, 'enrollmentId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function grant(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $enrollmentId, Request $request): Response
    {
        $balance = $this->balances->forEnrollment($teacher, $enrollmentId);
        if (null === $balance || !$balance->courseId->equals($id) || !$balance->active) {
            throw $this->createNotFoundException();
        }
        $course = $this->courses->findOwned($teacher, $id);
        $student = $this->students->findOwned($teacher, $balance->studentId);
        // No link is offered for an archived course or student; the ledger refusal stays the backstop.
        if (null === $course || $course->isArchived() || null === $student || $student->isArchived()) {
            throw $this->createNotFoundException();
        }

        $now = $this->clock->now();
        $timezone = $teacher->getTimezone();
        $today = $this->today($now, $timezone);
        $data = new GrantData();
        $data->date = new \DateTimeImmutable($today);
        $data->from = ReturnTarget::fromQuery($request->query->getString('from'))->value;
        $form = $this->createForm(GrantType::class, $data, ['max_date' => $today]);
        $form->handleRequest($request);
        $target = ReturnTarget::fromQuery($data->from);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $grant = $this->ledger->grant($teacher, $enrollmentId, $data->quantity(), $data->effectiveAt($now, $timezone), $data->note);
                $after = $this->balances->forEnrollment($teacher, $enrollmentId);
                $this->addFlash('success', $this->translator->trans('credits.flash.granted', [
                    'count' => $grant->getQuantity(),
                    'name' => $this->name($balance),
                    'course' => $balance->courseName,
                    'balance' => $after->balance ?? $balance->balance + $grant->getQuantity(),
                ], 'credits'));

                return $this->redirect($this->targetUrl($target, $balance), Response::HTTP_SEE_OTHER);
            } catch (CreditNotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidCreditGrant $refusal) {
                $this->addRefusal($form, $refusal, $data->quantity());
            }
        }

        $quantity = $data->quantity();

        return $this->render('credit/grant.html.twig', [
            'form' => $form,
            'balance' => $balance,
            'course' => $course,
            'after' => $balance->balance + ($quantity > 0 ? $quantity : CreditGrant::DEFAULT_QUANTITY),
            'back_url' => $this->targetUrl($target, $balance),
            'from' => $target->value,
        ]);
    }

    #[Route(
        '/students/{id}/credits/{grantId}/void',
        name: 'credit_void',
        requirements: ['id' => Requirement::UUID, 'grantId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function void(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $grantId, Request $request): Response
    {
        [$grant, $balance] = $this->voidableGrant($teacher, $id, $grantId);
        $data = new VoidData();
        $data->from = ReturnTarget::fromQuery($request->query->getString('from'), ReturnTarget::Student)->value;
        $form = $this->createForm(VoidType::class, $data);
        $form->handleRequest($request);
        $target = ReturnTarget::fromQuery($data->from, ReturnTarget::Student);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->ledger->void($teacher, $grantId, $data->reason);
                $this->addFlash('success', $this->translator->trans('credits.flash.voided', [
                    'count' => $grant->getQuantity(),
                    'name' => $this->name($balance),
                    'course' => $balance->courseName,
                ], 'credits'));

                return $this->redirect($this->targetUrl($target, $balance), Response::HTTP_SEE_OTHER);
            } catch (CreditNotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidCreditGrant $refusal) {
                $this->addRefusal($form, $refusal, $grant->getQuantity());
            }
        }

        $enrollment = $grant->getEnrollment();

        return $this->render('credit/void.html.twig', [
            'form' => $form,
            'grant' => $grant,
            'balance' => $balance,
            'after' => $balance->balance - $grant->getQuantity(),
            'course' => $enrollment->getCourse(),
            'student' => $enrollment->getStudent(),
            'back_url' => $this->targetUrl($target, $balance),
            'from' => $target->value,
        ]);
    }

    #[Route(
        '/students/{id}/credits/{grantId}/correct',
        name: 'credit_correct',
        requirements: ['id' => Requirement::UUID, 'grantId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function correct(#[CurrentUser] Teacher $teacher, Uuid $id, Uuid $grantId, Request $request): Response
    {
        [$grant, $balance] = $this->voidableGrant($teacher, $id, $grantId);
        $now = $this->clock->now();
        $timezone = $teacher->getTimezone();
        $data = CorrectData::fromGrant($grant, $timezone);
        $data->from = ReturnTarget::fromQuery($request->query->getString('from'), ReturnTarget::Student)->value;
        $form = $this->createForm(CorrectType::class, $data, ['max_date' => $this->today($now, $timezone)]);
        $form->handleRequest($request);
        $target = ReturnTarget::fromQuery($data->from, ReturnTarget::Student);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $new = $this->ledger->correct($teacher, $grantId, $data->quantity(), $data->effectiveAt($now, $timezone), $data->note, $data->reason);
                $this->addFlash('success', $this->translator->trans('credits.flash.corrected', [
                    'count' => $new->getQuantity(),
                    'name' => $this->name($balance),
                    'course' => $balance->courseName,
                ], 'credits'));

                return $this->redirect($this->targetUrl($target, $balance), Response::HTTP_SEE_OTHER);
            } catch (CreditNotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidCreditGrant $refusal) {
                $this->addRefusal($form, $refusal, $data->quantity());
            }
        }

        $enrollment = $grant->getEnrollment();

        return $this->render('credit/correct.html.twig', [
            'form' => $form,
            'grant' => $grant,
            'balance' => $balance,
            'course' => $enrollment->getCourse(),
            'student' => $enrollment->getStudent(),
            'back_url' => $this->targetUrl($target, $balance),
            'from' => $target->value,
        ]);
    }

    /**
     * The teacher's grant with this id, not voided (PD9) and of the student `{id}`, with its
     * enrollment's balance; 404 otherwise.
     *
     * @return array{CreditGrant, EnrollmentBalance}
     */
    private function voidableGrant(Teacher $teacher, Uuid $studentId, Uuid $grantId): array
    {
        $grant = $this->grants->findOwned($teacher, $grantId);
        if (null === $grant || $grant->isVoided() || !$grant->getEnrollment()->getStudent()->getId()->equals($studentId)) {
            throw $this->createNotFoundException();
        }
        $balance = $this->balances->forEnrollment($teacher, $grant->getEnrollment()->getId())
            ?? throw $this->createNotFoundException();

        return [$grant, $balance];
    }

    private function targetUrl(ReturnTarget $target, EnrollmentBalance $balance): string
    {
        return match ($target) {
            ReturnTarget::Course => $this->generateUrl('course_show', ['id' => $balance->courseId->toRfc4122()]),
            ReturnTarget::Student => $this->generateUrl('student_show', ['id' => $balance->studentId->toRfc4122()]),
            ReturnTarget::Dashboard => $this->generateUrl('dashboard'),
        };
    }

    /**
     * Puts a ledger refusal on the form: a date in the future on `date`, a note or reason that is
     * too long on its field, anything else on the form itself.
     *
     * @param FormInterface<mixed> $form
     */
    private function addRefusal(FormInterface $form, InvalidCreditGrant $refusal, int $quantity): void
    {
        // InvalidCreditGrant (#6) carries no translation key: recognise the case by the message of
        // the same named constructor, so the English exception text never reaches the page.
        [$key, $field, $parameters] = match ($refusal->getMessage()) {
            InvalidCreditGrant::grantedInFuture()->getMessage() => ['granted_in_future', 'date', []],
            InvalidCreditGrant::noteTooLong(CreditGrant::NOTE_MAX)->getMessage() => ['note_too_long', 'note', ['max' => CreditGrant::NOTE_MAX]],
            InvalidCreditGrant::reasonTooLong(CreditLedger::REASON_MAX)->getMessage() => ['reason_too_long', 'reason', ['max' => CreditLedger::REASON_MAX]],
            InvalidCreditGrant::quantityNotPositive($quantity)->getMessage() => ['quantity_not_positive', null, []],
            InvalidCreditGrant::alreadyVoided()->getMessage() => ['already_voided', null, []],
            InvalidCreditGrant::enrollmentNotActive()->getMessage() => ['enrollment_not_active', null, []],
            default => ['invalid', null, []],
        };
        $error = new FormError($this->translator->trans('credits.error.'.$key, $parameters, 'credits'));
        if (null !== $field && $form->has($field)) {
            $form->get($field)->addError($error);
        } else {
            $form->addError($error);
        }
    }

    /**
     * Today's date `Y-m-d` in the teacher's zone: the default and the latest effective date (PD5).
     */
    private function today(\DateTimeImmutable $now, string $timezone): string
    {
        return $now->setTimezone(new \DateTimeZone($timezone))->format('Y-m-d');
    }

    private function name(EnrollmentBalance $balance): string
    {
        return $balance->studentFirstName.' '.$balance->studentLastName;
    }
}
