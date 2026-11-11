<?php

declare(strict_types=1);

namespace App\Tests\Functional\Students;

use App\Account\Entity\Teacher;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Functional\LogsInTeacher;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The directory pages render what the read models return (#9): list, filter, search, empty
 * states, the student page with its courses, and the form pages.
 */
#[ResetDatabase]
final class StudentDirectoryPagesTest extends WebTestCase
{
    use LogsInTeacher;

    private KernelBrowser $client;
    private Teacher $teacher;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->teacher = $this->logInTeacher($this->client);
    }

    public function test_anonymous_visitor_is_sent_to_login(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/students');

        self::assertResponseRedirects('/login');
    }

    public function test_index_lists_own_active_students_by_last_name(): void
    {
        $this->seedDirectory();

        $this->client->request('GET', '/students');

        self::assertResponseIsSuccessful();
        self::assertSame(['Bruno Albrecht', 'Anna Meier'], $this->rowTitles());
        self::assertSelectorTextContains('.page-header', '2 students');
        self::assertSelectorTextContains('.list-row', 'Not in a course');
        self::assertAnySelectorTextContains('.list-row', '1 course');
        self::assertSelectorExists('a[aria-current="page"][href="/students"]');
    }

    public function test_archived_filter_lists_only_archived_students(): void
    {
        $this->seedDirectory();

        $this->client->request('GET', '/students?archived=1');

        self::assertResponseIsSuccessful();
        self::assertSame(['Dora Zeller'], $this->rowTitles());
        self::assertSelectorTextContains('.list-row', 'Archived');
        self::assertSelectorExists('input[type="hidden"][name="archived"][value="1"]');
    }

    public function test_search_by_last_first_and_full_name(): void
    {
        $this->seedDirectory();

        foreach (['mei', 'ANNA', 'anna%20mei'] as $q) {
            $this->client->request('GET', '/students?q='.$q);
            self::assertSame(['Anna Meier'], $this->rowTitles(), $q);
        }
        self::assertSelectorExists('input[name="q"][value="anna mei"]');
        self::assertSelectorTextContains('.students-search', 'Clear search');
    }

    public function test_search_without_match_shows_the_no_match_state(): void
    {
        $this->seedDirectory();

        $this->client->request('GET', '/students?q=xyz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state__title', 'No student matches');
        self::assertSelectorTextContains('.empty-state', '“xyz”');
    }

    public function test_teacher_without_students_sees_the_empty_state(): void
    {
        $this->client->request('GET', '/students');

        self::assertSelectorTextContains('.empty-state__title', 'No students yet');
        self::assertSelectorExists('.empty-state a[href="/students/new"]');

        $this->client->request('GET', '/students?archived=1');
        self::assertSelectorTextContains('.empty-state__title', 'No archived students');
    }

    public function test_show_renders_details_and_courses(): void
    {
        $student = StudentFactory::createOne([
            'teacher' => $this->teacher,
            'firstName' => 'Anna',
            'lastName' => 'Meier',
            'email' => 'anna@example.com',
            'notes' => "<b>Knee</b>\nsecond line",
        ]);
        $salsa = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Salsa', 'weekday' => 4, 'localStartTime' => new \DateTimeImmutable('1970-01-01 19:30:00')]);
        $tango = CourseFactory::createOne(['teacher' => $this->teacher, 'name' => 'Tango']);
        EnrollmentFactory::createOne(['student' => $student, 'course' => $salsa]);
        EnrollmentFactory::new()->left()->create(['student' => $student, 'course' => $tango]);

        $this->client->request('GET', '/students/'.$student->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Anna Meier');
        self::assertSelectorExists('a[href="mailto:anna@example.com"]');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('&lt;b&gt;Knee&lt;/b&gt;<br />', $html);
        self::assertStringNotContainsString('<b>Knee</b>', $html);
        self::assertSelectorExists(\sprintf('#student-courses-heading ~ .list a[href="/courses/%s"]', $salsa->getId()));
        self::assertSelectorTextContains('#student-courses-heading ~ .list', 'Thursdays · 7:30');
        self::assertSelectorTextContains('#student-earlier-heading ~ .list', 'Tango');
        self::assertSelectorTextContains('#student-earlier-heading ~ .list', 'Left on');
        self::assertSelectorExists('form[action$="/archive"] input[name="_token"]');
    }

    public function test_show_renders_while_the_credits_and_attendance_slots_are_absent(): void
    {
        $projectDir = $this->client->getKernel()->getProjectDir();
        // The #12/#13 partials are included with `ignore missing`; until those cycles land they do not exist.
        foreach (['_credits', '_attendance'] as $partial) {
            if (is_file(\sprintf('%s/templates/student/%s.html.twig', $projectDir, $partial))) {
                self::markTestSkipped(\sprintf('student/%s.html.twig exists; the slot is now covered by its own cycle.', $partial));
            }
        }
        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Anna', 'lastName' => 'Meier']);

        $this->client->request('GET', '/students/'.$student->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Anna Meier');
    }

    public function test_show_without_courses_or_details_shows_the_empty_states(): void
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher]);

        $this->client->request('GET', '/students/'.$student->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state__title', 'Not in a course yet');
        self::assertSelectorTextContains('.students-section', 'No email or notes.');
        self::assertSelectorNotExists('#student-earlier-heading');
    }

    public function test_archived_student_page_offers_restore(): void
    {
        $student = StudentFactory::new()->archived()->create(['teacher' => $this->teacher]);

        $this->client->request('GET', '/students/'.$student->getId());

        self::assertSelectorTextContains('.page-header', 'Archived');
        self::assertSelectorExists('form[action$="/restore"] input[name="_token"]');
        self::assertSelectorNotExists('form[action$="/archive"]');
        self::assertSelectorExists('a[href="/students?archived=1"]');
    }

    public function test_new_and_edit_render_four_labelled_fields(): void
    {
        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Anna']);

        foreach (['/students/new', '/students/'.$student->getId().'/edit'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            foreach (['firstName', 'lastName', 'email', 'notes'] as $field) {
                $input = $crawler->filter(\sprintf('[name="student[%s]"]', $field));
                self::assertCount(1, $input, $url.' '.$field);
                self::assertCount(1, $crawler->filter(\sprintf('label[for="%s"]', $input->attr('id'))), $url.' '.$field);
            }
        }
        self::assertSelectorExists('input[name="student[firstName]"][value="Anna"]');
    }

    private function seedDirectory(): void
    {
        $anna = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Anna', 'lastName' => 'Meier']);
        StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Bruno', 'lastName' => 'Albrecht']);
        StudentFactory::new()->archived()->create(['teacher' => $this->teacher, 'firstName' => 'Dora', 'lastName' => 'Zeller']);
        EnrollmentFactory::createOne(['student' => $anna, 'course' => CourseFactory::createOne(['teacher' => $this->teacher])]);
    }

    /**
     * @return list<string>
     */
    private function rowTitles(): array
    {
        $crawler = $this->client->getCrawler();

        return $crawler->filter('.list-row__title')->each(static fn ($node): string => trim($node->text()));
    }
}
