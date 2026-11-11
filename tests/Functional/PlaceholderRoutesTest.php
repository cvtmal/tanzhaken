<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Every S5 route existed from cycle #0 on and rendered the placeholder until its owner shipped it.
 * The last placeholders (checkin_manage, checkin_status) shipped with #16 (D-056), so the
 * placeholder provider and its tests are gone; the router still holds exactly the S5 routes.
 */
#[ResetDatabase]
final class PlaceholderRoutesTest extends WebTestCase
{
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
        'lesson_show', 'lesson_mark', 'lesson_complete',
        'credit_grant', 'credit_void', 'credit_correct',
        'checkin_manage', 'checkin_status', 'checkin_open', 'checkin_close',
    ];

    public function test_router_holds_exactly_the_s5_routes(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $names = array_values(array_filter(
            array_keys($router->getRouteCollection()->all()),
            static fn (string $name): bool => !str_starts_with($name, '_'),
        ));
        $expected = [...self::SHIPPED, 'health'];
        sort($names);
        sort($expected);

        self::assertSame($expected, $names);
        self::assertNotNull($router->getRouteCollection()->get('_styleguide'));
    }
}
