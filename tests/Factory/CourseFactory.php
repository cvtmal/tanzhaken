<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Courses\Entity\Course;
use App\Shared\Time\Timezones;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Course>
 */
final class CourseFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Course::class;
    }

    public function archived(): self
    {
        return $this->afterInstantiate(static function (Course $course): void {
            $course->archive(Clock::get()->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $now = Clock::get()->now();

        return [
            'teacher' => TeacherFactory::new(),
            'name' => ucfirst(self::faker()->word()).' class',
            'timezone' => Timezones::DEFAULT,
            'weekday' => self::faker()->numberBetween(1, 7),
            'localStartTime' => new \DateTimeImmutable('1970-01-01 19:00:00'),
            'durationMinutes' => 60,
            'startsOn' => $now->setTime(0, 0),
            'now' => $now,
        ];
    }
}
