<?php

declare(strict_types=1);

namespace App\Tests\Unit\Courses;

use App\Courses\Exception\InvalidCourse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class InvalidCourseTest extends TestCase
{
    private const CATALOGUE = __DIR__.'/../../../translations/courses+intl-icu.en.yaml';

    /**
     * @return iterable<string, array{InvalidCourse}>
     */
    public static function refusals(): iterable
    {
        $arguments = [
            'tooLong' => ['name', 120],
            'invalidTimezone' => ['Mars/Olympus'],
            'invalidWeekday' => [8],
            'invalidDuration' => [0],
            'invalidWeeks' => [105],
            'extensionTooFar' => [104],
            'labelTaken' => ['Alex'],
        ];

        foreach ((new \ReflectionClass(InvalidCourse::class))->getMethods(\ReflectionMethod::IS_STATIC) as $method) {
            if (!$method->isPublic()) {
                continue;
            }
            $refusal = $method->invoke(null, ...$arguments[$method->getName()] ?? []);
            self::assertInstanceOf(InvalidCourse::class, $refusal);

            yield $method->getName() => [$refusal];
        }
    }

    #[DataProvider('refusals')]
    public function test_every_refusal_has_a_translated_course_error_key(InvalidCourse $refusal): void
    {
        $key = $refusal->translationKey();
        self::assertStringStartsWith('course.error.', $key);

        $catalogue = Yaml::parseFile(self::CATALOGUE);
        self::assertIsArray($catalogue);
        $message = $catalogue;
        foreach (explode('.', $key) as $part) {
            self::assertIsArray($message, $key);
            self::assertArrayHasKey($part, $message, \sprintf('Missing %s in the courses catalogue.', $key));
            $message = $message[$part];
        }
        self::assertIsString($message, $key);

        $formatted = \MessageFormatter::formatMessage('en', $message, $refusal->translationParameters());
        self::assertIsString($formatted, \sprintf('%s is not a valid ICU message.', $key));
        self::assertStringNotContainsString('{', $formatted, \sprintf('%s has an unfilled parameter.', $key));
    }

    public function test_parameters_carry_the_values_of_the_message(): void
    {
        self::assertSame(['field' => 'name', 'max' => 120], InvalidCourse::tooLong('name', 120)->translationParameters());
        self::assertSame(['label' => 'Alex'], InvalidCourse::labelTaken('Alex')->translationParameters());
        self::assertSame([], InvalidCourse::alreadyEnrolled()->translationParameters());
        self::assertSame('The course name must not be longer than 120 characters.', InvalidCourse::tooLong('name', 120)->getMessage());
    }
}
