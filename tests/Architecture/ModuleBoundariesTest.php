<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Test\Builder\Rule;

/**
 * phpat rules (run by phpstan, not PHPUnit): one module boundary per module.
 */
final class ModuleBoundariesTest extends ArchitectureRules
{
    public function test_shared_boundaries(): Rule
    {
        return $this->boundaryRule('Shared');
    }

    public function test_account_boundaries(): Rule
    {
        return $this->boundaryRule('Account');
    }

    public function test_students_boundaries(): Rule
    {
        return $this->boundaryRule('Students');
    }

    public function test_courses_boundaries(): Rule
    {
        return $this->boundaryRule('Courses');
    }

    public function test_credits_boundaries(): Rule
    {
        return $this->boundaryRule('Credits');
    }

    public function test_attendance_boundaries(): Rule
    {
        return $this->boundaryRule('Attendance');
    }

    public function test_checkin_boundaries(): Rule
    {
        return $this->boundaryRule('CheckIn');
    }

    public function test_dashboard_boundaries(): Rule
    {
        return $this->boundaryRule('Dashboard');
    }
}
