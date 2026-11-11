<?php

declare(strict_types=1);

/*
 * Child process of CheckInConcurrencyTest: boots the test kernel against the temporary
 * SQLite file in DATABASE_URL (a real connection, not DAMA's static one), waits until the
 * shared start time and records one scan. Prints the outcome; on failure the exception to
 * STDERR and exit code 1.
 *
 * Usage: php record_check_in.php <lesson-uuid> <student-uuid> <start-at-unix-float>
 */

use App\Attendance\Service\CheckInRecorder;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

$projectDir = dirname(__DIR__, 4);
require $projectDir.'/vendor/autoload.php';

try {
    $lessonId = $_SERVER['argv'][1] ?? null;
    $studentId = $_SERVER['argv'][2] ?? null;
    $startAt = $_SERVER['argv'][3] ?? null;
    if (!is_string($lessonId) || !is_string($studentId) || !is_numeric($startAt)) {
        throw new InvalidArgumentException('Usage: record_check_in.php <lesson-uuid> <student-uuid> <start-at>');
    }

    (new Dotenv())->bootEnv($projectDir.'/.env');
    // Debug true, as bin/console runs in test: the children reuse the container the migrate child compiled.
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    if (!$registry instanceof ManagerRegistry) {
        throw new LogicException('No doctrine registry.');
    }
    $connection = $registry->getConnection();
    if (!$connection instanceof Connection) {
        throw new LogicException('No DBAL connection.');
    }
    $recorder = new CheckInRecorder($connection, new NativeClock());

    $wait = (float) $startAt - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }

    echo $recorder->recordCheckIn(Uuid::fromString($lessonId), Uuid::fromString($studentId))->value;
} catch (Throwable $e) {
    fwrite(\STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}
