<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Courses\Entity\Course;
use App\Courses\Entity\Enrollment;
use App\Students\Entity\Student;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Course and student always share one teacher: the parent that is passed (or else a new
 * course) decides the teacher, and the missing parent is built for that teacher.
 *
 * @extends PersistentObjectFactory<Enrollment>
 */
final class EnrollmentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Enrollment::class;
    }

    public function left(): self
    {
        return $this->afterInstantiate(static function (Enrollment $enrollment): void {
            $enrollment->leave(Clock::get()->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'now' => Clock::get()->now(),
        ];
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters, string $class, self $factory): array {
            $course = SameTeacher::resolve($parameters['course'] ?? null, $factory->isPersisting());
            $student = SameTeacher::resolve($parameters['student'] ?? null, $factory->isPersisting());

            if (!$course instanceof Course) {
                $course = SameTeacher::resolve(
                    $student instanceof Student ? CourseFactory::new(['teacher' => $student->getTeacher()]) : CourseFactory::new(),
                    $factory->isPersisting(),
                );
            }
            if (!$student instanceof Student && $course instanceof Course) {
                $student = SameTeacher::resolve(StudentFactory::new(['teacher' => $course->getTeacher()]), $factory->isPersisting());
            }

            $parameters['course'] = $course;
            $parameters['student'] = $student;

            return $parameters;
        });
    }
}
