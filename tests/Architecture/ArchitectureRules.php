<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * The module map (docs/rpi-cycles.md S2) in one table. A module may use itself,
 * Shared and the modules listed for it; every other module is forbidden.
 */
abstract class ArchitectureRules
{
    final public const MODULES = ['Shared', 'Account', 'Students', 'Courses', 'Credits', 'Attendance', 'CheckIn', 'Dashboard'];

    final public const ALLOWED = [
        'Shared' => [],
        'Account' => [],
        'Students' => ['Account'],
        'Courses' => ['Students', 'Account'],
        'Credits' => ['Courses', 'Students', 'Account'],
        'Attendance' => ['Credits', 'Courses', 'Students', 'Account'],
        'CheckIn' => ['Attendance', 'Credits', 'Courses', 'Students', 'Account'],
        'Dashboard' => ['Account', 'Students', 'Courses', 'Credits', 'Attendance', 'CheckIn'],
    ];

    protected function boundaryRule(string $module): Rule
    {
        $allowed = [$module, 'Shared', ...self::ALLOWED[$module]];
        if ('Shared' === $module) {
            $allowed = ['Shared'];
        }

        // Application code never depends on test code; this also keeps the
        // target list non-empty for Dashboard, which may use every module.
        $forbidden = [Selector::inNamespace('App\Tests')];
        foreach (array_diff(self::MODULES, $allowed) as $other) {
            $forbidden[] = Selector::inNamespace('App\\'.$other);
        }

        return PHPat::rule()
            ->classes(Selector::inNamespace('App\\'.$module))
            ->shouldNot()->dependOn()
            ->classes(...$forbidden)
            ->because(\sprintf('%s may use only %s (docs/rpi-cycles.md S2).', $module, implode(', ', $allowed)));
    }
}
