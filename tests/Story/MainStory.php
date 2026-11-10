<?php

declare(strict_types=1);

namespace App\Tests\Story;

use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * Demo data for `make seed`: one teacher, two courses, students, credits, attendance.
 */
#[AsFixture(name: 'main')]
final class MainStory extends Story
{
    public function build(): void
    {
        // Filled by cycle #3 (domain-model) once the entities and factories exist.
    }
}
