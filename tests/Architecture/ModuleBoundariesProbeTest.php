<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Proves the module rules bite: phpstan run on two fixture modules must report
 * the forbidden Students -> Courses edge, and nothing else.
 */
final class ModuleBoundariesProbeTest extends TestCase
{
    public function test_a_forbidden_module_dependency_fails_phpstan(): void
    {
        $projectDir = \dirname(__DIR__, 2);
        $process = new Process(
            ['vendor/bin/phpstan', 'analyse', '-c', 'tests/Architecture/Fixtures/phpstan-module-boundaries.neon', '--no-progress', '--error-format=json', '--memory-limit=1G'],
            $projectDir,
        );
        $process->setTimeout(120);
        $process->run();

        self::assertNotSame(0, $process->getExitCode(), $process->getErrorOutput());

        $result = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame(0, $result['totals']['errors'], $process->getOutput());
        self::assertSame(1, $result['totals']['file_errors'], $process->getOutput());

        $files = array_keys($result['files']);
        self::assertCount(1, $files);
        self::assertStringEndsWith('Students/Probe/ForbiddenCourseUser.php', (string) $files[0]);
    }
}
