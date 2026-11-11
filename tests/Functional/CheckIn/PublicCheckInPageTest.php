<?php

declare(strict_types=1);

namespace App\Tests\Functional\CheckIn;

use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Tests\Factory\AttendanceFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Journeys B and C of the public check-in (#14, QR-2–QR-5): roster, confirm, result, the repeat,
 * the teacher's mark, the 422 and the cross-site refusal, each with the S8 headers and no cookie.
 */
#[ResetDatabase]
final class PublicCheckInPageTest extends WebTestCase
{
    use OpensPublicCheckIn;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->resetCheckInLimits($this->browser);
    }

    public function test_journey_b_a_student_taps_the_name_confirms_and_sees_the_pass(): void
    {
        [, $lesson, [$anna]] = self::openPublicCheckIn();
        self::assertSame(10, self::balanceOf($anna));

        $crawler = $this->browser->request('GET', '/c/'.self::TOKEN);

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', self::COURSE_NAME);
        self::assertSelectorTextSame('.checkin-lesson__meta', 'with '.self::TEACHER_NAME.' · '.self::lessonTime($lesson));
        // 18:00 UTC is 7:00 PM (winter) or 8:00 PM (summer) in Berlin, in the 12 h `en` format (D-042).
        $meta = str_replace("\u{202F}", ' ', $crawler->filter('.checkin-lesson__meta')->text());
        self::assertMatchesRegularExpression('/, [78]:00 PM$/', $meta);
        self::assertDoesNotMatchRegularExpression('/\d{2}:\d{2}/', $meta, 'No 24 h time.');
        self::assertEqualsCanonicalizing(['Anna B.', 'Ben K.'], $crawler->filter('.checkin-tile__name')->each(static fn ($tile): string => $tile->text()));
        self::assertCount(2, $crawler->filter('form.checkin-roster input[type="radio"][name="enrollment"]'));
        // The footer status the JS fills: translated text around the label, which it prints in bold (H1).
        self::assertSame('Checking in as ', $crawler->filter('[data-checkin-status]')->attr('data-checkin-status-before'));
        self::assertSame('', $crawler->filter('[data-checkin-status]')->attr('data-checkin-status-after'));
        self::assertEqualsCanonicalizing(['Anna B.', 'Ben K.'], $crawler->filter('input[data-checkin-label]')->each(static fn ($input): ?string => $input->attr('data-checkin-label')));

        $crawler = $this->browser->submitForm('Confirm attendance', ['enrollment' => $anna->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', "You're checked in, Anna B.");
        self::assertSelectorTextContains('.checkin-result__lesson', self::COURSE_NAME.' · '.self::lessonTime($lesson));
        self::assertSame('9', $crawler->filter('.checkin-pass__number')->text());
        self::assertSame([['status' => AttendanceStatus::Present->value, 'source' => AttendanceSource::StudentQr->value]], self::attendanceRows());
        self::assertSame(9, self::balanceOf($anna));
    }

    public function test_journey_c_a_second_and_a_repeated_post_answer_already_checked_in(): void
    {
        [, , [$anna]] = self::openPublicCheckIn();
        self::confirm($this->browser, $anna->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(200);

        $crawler = self::confirm($this->browser, $anna->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', 'Already checked in');
        self::assertSame('9', $crawler->filter('.checkin-pass__number')->text());

        // A refresh of the result page re-sends the POST.
        $crawler = $this->browser->reload();

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', 'Already checked in');
        self::assertSame('9', $crawler->filter('.checkin-pass__number')->text());
        self::assertSame(1, self::attendanceCount());
        self::assertSame(9, self::balanceOf($anna));
    }

    public function test_a_lesson_the_teacher_marked_is_not_overwritten(): void
    {
        [, $lesson, [$anna]] = self::openPublicCheckIn();
        AttendanceFactory::createOne(['lessonSession' => $lesson, 'student' => $anna->getStudent(), 'status' => AttendanceStatus::Absent]);
        $balance = self::balanceOf($anna);

        self::confirm($this->browser, $anna->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', 'Your teacher has already marked this lesson.');
        self::assertSelectorNotExists('.checkin-pass');
        self::assertSame([['status' => AttendanceStatus::Absent->value, 'source' => AttendanceSource::Teacher->value]], self::attendanceRows());
        self::assertSame($balance, self::balanceOf($anna));
    }

    public function test_a_post_without_an_enrollment_re_renders_the_roster_with_422(): void
    {
        self::openPublicCheckIn();

        $crawler = self::confirm($this->browser, null);

        self::assertResponseStatusCodeSame(422);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('.checkin-roster .alert--danger', 'Tap your name, then confirm.');
        self::assertCount(2, $crawler->filter('.checkin-tile__name'));
        self::assertSame(0, self::attendanceCount());
    }

    public function test_a_post_with_an_enrollment_that_is_not_a_uuid_re_renders_the_roster_with_422(): void
    {
        self::openPublicCheckIn();

        $crawler = self::confirm($this->browser, 'not-a-uuid');

        self::assertResponseStatusCodeSame(422);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('.checkin-roster .alert--danger', 'Tap your name, then confirm.');
        self::assertCount(2, $crawler->filter('.checkin-tile__name'));
        self::assertSame(0, self::attendanceCount());
    }

    public function test_a_cross_site_post_is_refused_with_403(): void
    {
        [, , [$anna]] = self::openPublicCheckIn();

        self::confirm($this->browser, $anna->getId()->toRfc4122(), server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', "This check-in isn't open");
        self::assertSame(0, self::attendanceCount());
        self::assertSame(10, self::balanceOf($anna));
    }

    public function test_a_same_origin_post_is_accepted(): void
    {
        [, , [$anna]] = self::openPublicCheckIn();

        self::confirm($this->browser, $anna->getId()->toRfc4122(), server: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', "You're checked in, Anna B.");
        self::assertSame(1, self::attendanceCount());
    }

    public function test_an_empty_roster_shows_the_empty_state_and_no_form(): void
    {
        self::openPublicCheckIn([]);

        $this->browser->request('GET', '/c/'.self::TOKEN);

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('.empty-state__title', 'No one is on the list yet.');
        self::assertSelectorNotExists('form');
    }

    public function test_a_check_in_without_credits_shows_the_lesson_owed(): void
    {
        [, , [$anna]] = self::openPublicCheckIn([['firstName' => 'Anna', 'lastName' => 'Berg', 'credits' => 0]]);

        $crawler = self::confirm($this->browser, $anna->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        self::assertPublicResponse($this->browser);
        self::assertSelectorTextSame('h1', "You're checked in, Anna B.");
        self::assertSame('1 lesson owed', $crawler->filter('.checkin-pass__owed')->text());
        self::assertSelectorNotExists('.checkin-pass__number');
        self::assertSame(-1, self::balanceOf($anna));
    }
}
