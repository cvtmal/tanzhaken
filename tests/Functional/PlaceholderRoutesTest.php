<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Every S5 route exists from cycle #0 on and renders the placeholder until its owner ships it.
 * When a cycle replaces a placeholder with the real page, it moves the route's row from
 * placeholderRoutes() to SHIPPED.
 *
 * Every remaining placeholder is a teacher route: it needs a logged-in teacher (ACC-5, #4). The last
 * public placeholder (privacy) shipped with #18, so the PUBLIC list is gone.
 */
#[ResetDatabase]
final class PlaceholderRoutesTest extends WebTestCase
{
    use LogsInTeacher;

    /** S5 routes whose real page has shipped; they are tested by their own cycle's tests. */
    private const SHIPPED = [
        'home', 'register', 'login', 'logout', 'privacy',
        'password_forgot', 'password_reset', 'account', 'account_profile', 'account_password', 'account_delete',
        'student_index', 'student_new', 'student_show', 'student_edit', 'student_archive', 'student_restore',
        'checkin_public', 'checkin_public_confirm',
        'dashboard',
        'course_index', 'course_new', 'course_show', 'course_edit',
        'course_archive', 'course_extend', 'course_lesson_add', 'course_lesson_reschedule', 'course_lesson_cancel',
        'course_lesson_reinstate', 'course_enroll', 'course_enrollment_remove', 'course_enrollment_label',
        'course_export',
    ];

    /**
     * @return iterable<string, array{string, array<string, string>, string}>
     */
    public static function placeholderRoutes(): iterable
    {
        yield 'lesson_show' => ['lesson_show', ['id' => 'x', 'lessonId' => 'y'], '/courses/x/lessons/y'];
        yield 'checkin_manage' => ['checkin_manage', ['id' => 'x'], '/lessons/x/check-in'];
        yield 'checkin_status' => ['checkin_status', ['id' => 'x'], '/lessons/x/check-in/status'];
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('placeholderRoutes')]
    public function test_route_renders_its_placeholder(string $name, array $params, string $expectedPath): void
    {
        $client = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        self::assertSame($expectedPath, $router->generate($name, $params));

        $this->logInTeacher($client);
        $client->request('GET', $expectedPath);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('[data-route="%s"]', $name));
    }

    /**
     * @param array<string, string> $params
     */
    #[DataProvider('placeholderRoutes')]
    public function test_teacher_placeholder_redirects_anonymous_visitors_to_login(string $name, array $params, string $expectedPath): void
    {
        $client = self::createClient();
        $client->request('GET', $expectedPath);

        self::assertResponseRedirects('/login');
    }

    public function test_router_holds_exactly_the_s5_routes(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $names = array_values(array_filter(
            array_keys($router->getRouteCollection()->all()),
            static fn (string $name): bool => !str_starts_with($name, '_'),
        ));
        $expected = [...array_keys(iterator_to_array(self::placeholderRoutes())), ...self::SHIPPED, 'health'];
        sort($names);
        sort($expected);

        self::assertSame($expected, $names);
        self::assertNotNull($router->getRouteCollection()->get('_styleguide'));
    }
}
