<?php

declare(strict_types=1);

namespace App\Tests\Functional\Courses;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Uid\Uuid;

/**
 * Helpers for the course page tests (#10): pick a form by its `action` (per-row buttons repeat),
 * read the flash region, count rows with DBAL. Use in a WebTestCase after `createClient()`.
 */
trait CoursePages
{
    /**
     * The one form of the page that posts to `$action` (an absolute path).
     */
    private static function formFor(Crawler $crawler, string $action): Form
    {
        $forms = $crawler->filter(\sprintf('form[action="%s"]', $action));
        self::assertCount(1, $forms, \sprintf('One form posting to %s.', $action));

        return $forms->form();
    }

    /**
     * Follows the 303 to the course page and asserts the flash of `$type` (`success` or `error`).
     */
    private static function followToFlash(KernelBrowser $client, string $coursePath, string $type, string $text): Crawler
    {
        self::assertResponseRedirects($coursePath, 303);
        $crawler = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('error' === $type ? '.flash-region .alert--danger' : '.flash-region .toast', $text);

        return $crawler;
    }

    private static function coursePath(Uuid $id, string $suffix = ''): string
    {
        return '/courses/'.$id->toRfc4122().$suffix;
    }

    private static function db(KernelBrowser $client): Connection
    {
        $connection = $client->getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * Rows of `$table` whose `$column` is the given id (UUIDs are stored as 16-byte binary).
     */
    private static function countWhere(KernelBrowser $client, string $table, string $column, Uuid $id): int
    {
        return (int) self::db($client)->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s = ?', $table, $column), [$id->toBinary()]);
    }

    private static function countRows(KernelBrowser $client, string $table): int
    {
        return (int) self::db($client)->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
