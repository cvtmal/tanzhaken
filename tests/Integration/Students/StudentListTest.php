<?php

declare(strict_types=1);

namespace App\Tests\Integration\Students;

use App\Account\Entity\Teacher;
use App\Students\ReadModel\StudentList;
use App\Students\ReadModel\StudentListItem;
use App\Tests\Factory\CourseFactory;
use App\Tests\Factory\EnrollmentFactory;
use App\Tests\Factory\StudentFactory;
use App\Tests\Factory\TeacherFactory;
use App\Tests\Integration\DbalRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class StudentListTest extends KernelTestCase
{
    use DbalRows;

    private Teacher $teacher;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->teacher = TeacherFactory::createOne();
        StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Anna', 'lastName' => 'Meier', 'email' => 'anna@example.com']);
        StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'bruno', 'lastName' => 'Albrecht']);
        StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Clara', 'lastName' => 'albrecht']);
        StudentFactory::new()->archived()->create(['teacher' => $this->teacher, 'firstName' => 'Dora', 'lastName' => 'Zeller']);
        StudentFactory::createOne(['firstName' => 'Anna', 'lastName' => 'Fremd']);
    }

    public function test_lists_own_active_students_by_last_then_first_name(): void
    {
        self::assertSame(['bruno Albrecht', 'Clara albrecht', 'Anna Meier'], self::names($this->list()));
    }

    public function test_archived_filter_lists_only_archived_students(): void
    {
        $items = $this->list(archived: true);

        self::assertSame(['Dora Zeller'], self::names($items));
        self::assertTrue($items[0]->isArchived());
    }

    public function test_maps_fields_and_initials(): void
    {
        $anna = $this->list('meier')[0];

        self::assertSame('Anna', $anna->firstName);
        self::assertSame('Meier', $anna->lastName);
        self::assertSame('anna@example.com', $anna->email);
        self::assertNull($anna->archivedAt);
        self::assertSame('AM', $anna->initials());
        self::assertSame('BA', $this->list('bruno')[0]->initials());
    }

    public function test_search_matches_first_last_and_full_name_case_insensitively(): void
    {
        self::assertSame(['Anna Meier'], self::names($this->list('ANNA')));
        self::assertSame(['Anna Meier'], self::names($this->list('mei')));
        self::assertSame(['Anna Meier'], self::names($this->list('  anna mei ')));
        self::assertSame(['bruno Albrecht', 'Clara albrecht'], self::names($this->list('ALBRECHT')));
        self::assertSame([], self::names($this->list('meier anna')));
        self::assertSame([], self::names($this->list('zeller')));
        self::assertSame(['Dora Zeller'], self::names($this->list('zeller', true)));
    }

    public function test_percent_and_underscore_are_searched_literally(): void
    {
        StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Per_cent', 'lastName' => '100%']);

        self::assertSame(['Per_cent 100%'], self::names($this->list('%')));
        self::assertSame(['Per_cent 100%'], self::names($this->list('_')));
        self::assertSame(['Per_cent 100%'], self::names($this->list('r_c')));
        self::assertSame([], self::names($this->list('a_n')));
        self::assertSame([], self::names($this->list('!')));
    }

    public function test_empty_search_returns_all(): void
    {
        self::assertCount(3, $this->list('   '));
    }

    public function test_active_course_count_ignores_left_enrollments_and_archived_courses(): void
    {
        $anna = $this->list('meier')[0];
        self::assertSame(0, $anna->activeCourseCount);

        $student = StudentFactory::createOne(['teacher' => $this->teacher, 'firstName' => 'Emil', 'lastName' => 'Brunner']);
        EnrollmentFactory::createOne(['student' => $student, 'course' => CourseFactory::createOne(['teacher' => $this->teacher])]);
        EnrollmentFactory::createOne(['student' => $student, 'course' => CourseFactory::createOne(['teacher' => $this->teacher])]);
        EnrollmentFactory::new()->left()->create(['student' => $student, 'course' => CourseFactory::createOne(['teacher' => $this->teacher])]);
        EnrollmentFactory::createOne(['student' => $student, 'course' => CourseFactory::new()->archived()->create(['teacher' => $this->teacher])]);

        self::assertSame(2, $this->list('brunner')[0]->activeCourseCount);
    }

    /**
     * @return list<StudentListItem>
     */
    private function list(string $search = '', bool $archived = false): array
    {
        return (new StudentList(self::connection()))->forTeacher($this->teacher, $search, $archived);
    }

    /**
     * @param list<StudentListItem> $items
     *
     * @return list<string>
     */
    private static function names(array $items): array
    {
        return array_map(static fn (StudentListItem $item): string => $item->fullName(), $items);
    }
}
