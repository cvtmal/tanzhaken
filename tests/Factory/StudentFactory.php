<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Students\Entity\Student;
use Symfony\Component\Clock\Clock;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Student>
 */
final class StudentFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Student::class;
    }

    public function archived(): self
    {
        return $this->afterInstantiate(static function (Student $student): void {
            $student->archive(Clock::get()->now());
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'teacher' => TeacherFactory::new(),
            'firstName' => self::faker()->firstName(),
            'lastName' => self::faker()->lastName(),
            'now' => Clock::get()->now(),
        ];
    }
}
