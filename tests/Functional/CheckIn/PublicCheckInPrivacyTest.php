<?php

declare(strict_types=1);

namespace App\Tests\Functional\CheckIn;

use App\CheckIn\Service\CheckInThrottle;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The trust test of #14 (QR-7, S4): no public response carries an email, a note, a student's full
 * last name, a student id or another student's balance. The teacher's display name is shown by
 * design (QR-2). The schema has no phone field; emails and notes are every contact and free-text
 * field a student or teacher has.
 */
#[ResetDatabase]
final class PublicCheckInPrivacyTest extends WebTestCase
{
    use OpensPublicCheckIn;

    private const TEACHER_EMAIL = 'mara.lindqvist@tanzraum.example';
    private const ANNA = ['firstName' => 'Anna', 'lastName' => 'Vanderhoeven', 'email' => 'anna.vanderhoeven@example.com', 'notes' => 'Knee injury in March'];
    private const BEN = ['firstName' => 'Ben', 'lastName' => 'Kowalczykowski', 'email' => 'ben.kowalczykowski@example.com', 'notes' => 'Pays in cash after class', 'credits' => 4];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->resetCheckInLimits($this->browser);
    }

    public function test_no_public_response_discloses_private_data(): void
    {
        [, , [$anna, $ben]] = self::openPublicCheckIn([self::ANNA, self::BEN], ['email' => self::TEACHER_EMAIL]);
        $annaId = $anna->getId()->toRfc4122();
        $bodies = [];
        $resultBodies = [];

        $this->browser->request('GET', '/c/'.self::TOKEN);
        $bodies['roster'] = $this->body(200);
        // What the page may show (QR-2/QR-3), so the absences below are not vacuous.
        self::assertStringContainsString(self::TEACHER_NAME, $bodies['roster']);
        self::assertStringContainsString('Anna V.', $bodies['roster']);
        self::assertStringContainsString('Ben K.', $bodies['roster']);
        self::confirm($this->browser, null);
        $bodies['422'] = $this->body(422);
        $recorded = self::confirm($this->browser, $annaId);
        $resultBodies['recorded'] = $bodies['recorded'] = $this->body(200);
        self::assertOnlyOwnPass($recorded, 9);
        $already = self::confirm($this->browser, $annaId);
        $resultBodies['already'] = $bodies['already'] = $this->body(200);
        self::assertOnlyOwnPass($already, 9);
        self::confirm($this->browser, $annaId, server: ['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $bodies['403'] = $this->body(403);
        $this->browser->request('GET', '/c/unknown-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $bodies['404'] = $this->body(404);
        self::exhaustLimit(static fn (CheckInThrottle $throttle): ?\DateTimeImmutable => $throttle->allowIp('127.0.0.1'));
        $this->browser->request('GET', '/c/'.self::TOKEN);
        $bodies['429'] = $this->body(429);

        $secrets = [
            self::TEACHER_EMAIL,
            self::ANNA['email'], self::ANNA['notes'], self::ANNA['lastName'],
            self::BEN['email'], self::BEN['notes'], self::BEN['lastName'],
            $anna->getStudent()->getId()->toRfc4122(), $anna->getStudent()->getId()->toBase58(),
            $ben->getStudent()->getId()->toRfc4122(), $ben->getStudent()->getId()->toBase58(),
        ];
        foreach ($bodies as $page => $body) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsStringIgnoringCase($secret, $body, \sprintf('The %s page discloses "%s".', $page, $secret));
            }
        }
        // The roster lists every enrollment id (the radio values); a result page names only its own.
        foreach ($resultBodies as $page => $body) {
            self::assertStringNotContainsString($ben->getId()->toRfc4122(), $body, \sprintf('The %s page discloses the other enrollment.', $page));
        }
        self::assertSame(4, self::balanceOf($ben));
    }

    private function body(int $status): string
    {
        self::assertResponseStatusCodeSame($status);

        return (string) $this->browser->getResponse()->getContent();
    }

    private static function assertOnlyOwnPass(Crawler $crawler, int $balance): void
    {
        self::assertCount(1, $crawler->filter('.checkin-pass'));
        self::assertCount(1, $crawler->filter('.checkin-pass__number'));
        self::assertSame((string) $balance, $crawler->filter('.checkin-pass__number')->text());
    }
}
