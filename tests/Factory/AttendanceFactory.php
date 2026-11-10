<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Attendance\Entity\Attendance;
use App\Attendance\Entity\AttendanceSource;
use App\Attendance\Entity\AttendanceStatus;
use App\Courses\Entity\LessonSession;
use App\Students\Entity\Student;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Lesson and student always share one teacher: the parent that is passed (or else a new
 * lesson) decides the teacher, and the missing parent is built for that teacher.
 *
 * @extends PersistentObjectFactory<Attendance>
 */
final class AttendanceFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Attendance::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'status' => AttendanceStatus::Present,
            'source' => AttendanceSource::Teacher,
            'now' => Clock::get()->now(),
        ];
    }

    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters, string $class, self $factory): array {
            $lesson = SameTeacher::resolve($parameters['lessonSession'] ?? null, $factory->isPersisting());
            $student = SameTeacher::resolve($parameters['student'] ?? null, $factory->isPersisting());

            if (!$lesson instanceof LessonSession) {
                $lesson = SameTeacher::resolve(
                    $student instanceof Student
                        ? LessonSessionFactory::new(['course' => CourseFactory::new(['teacher' => $student->getTeacher()])])
                        : LessonSessionFactory::new(),
                    $factory->isPersisting(),
                );
            }
            if (!$student instanceof Student && $lesson instanceof LessonSession) {
                $student = SameTeacher::resolve(StudentFactory::new(['teacher' => $lesson->getCourse()->getTeacher()]), $factory->isPersisting());
            }

            $parameters['lessonSession'] = $lesson;
            $parameters['student'] = $student;

            return $parameters;
        });
    }
}
