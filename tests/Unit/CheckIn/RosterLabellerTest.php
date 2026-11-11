<?php

declare(strict_types=1);

namespace App\Tests\Unit\CheckIn;

use App\CheckIn\ReadModel\RosterEntry;
use App\CheckIn\Service\RosterLabel;
use App\CheckIn\Service\RosterLabeller;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RosterLabellerTest extends TestCase
{
    public function test_first_name_and_last_initial(): void
    {
        self::assertSame(['Anna M.'], self::labels([self::entry('Anna', 'Müller')]));
    }

    public function test_custom_label_wins_and_is_trimmed(): void
    {
        self::assertSame(['Annie'], self::labels([self::entry('Anna', 'Müller', '  Annie ')]));
    }

    public function test_blank_custom_label_falls_back(): void
    {
        self::assertSame(['Anna M.'], self::labels([self::entry('Anna', 'Müller', '   ')]));
    }

    public function test_colliding_initials_grow_by_one_character(): void
    {
        self::assertSame(['Anna Ma.', 'Anna Mü.'], self::labels([self::entry('Anna', 'Müller'), self::entry('Anna', 'Maier')]));
    }

    public function test_growth_stops_at_three_characters_and_numbers_by_joined_at(): void
    {
        $mulheim = self::entry('Anna', 'Mülheim', null, '2026-01-01 10:00:00');
        $muller = self::entry('Anna', 'Müller', null, '2026-01-02 10:00:00');
        $maier = self::entry('Anna', 'Maier', null, '2026-01-03 10:00:00');

        $byEnrollment = self::byEnrollment((new RosterLabeller())->label([$muller, $mulheim, $maier]));

        self::assertSame('Anna Mül.', $byEnrollment[$mulheim->enrollmentId->toRfc4122()]);
        self::assertSame('Anna Mül. (2)', $byEnrollment[$muller->enrollmentId->toRfc4122()]);
        self::assertSame('Anna Ma.', $byEnrollment[$maier->enrollmentId->toRfc4122()]);
    }

    public function test_identical_full_names_are_numbered_and_never_show_the_last_name(): void
    {
        $first = self::entry('Anna', 'Müller', null, '2026-01-01 10:00:00');
        $second = self::entry('Anna', 'Müller', null, '2026-01-02 10:00:00');

        $byEnrollment = self::byEnrollment((new RosterLabeller())->label([$second, $first]));

        self::assertSame('Anna Mül.', $byEnrollment[$first->enrollmentId->toRfc4122()]);
        self::assertSame('Anna Mül. (2)', $byEnrollment[$second->enrollmentId->toRfc4122()]);
        foreach ($byEnrollment as $label) {
            self::assertStringNotContainsString('Müller', $label);
        }
    }

    public function test_two_letter_last_name_is_never_shown_in_full(): void
    {
        $labels = self::labels([
            self::entry('Anna', 'Li', null, '2026-01-01 10:00:00'),
            self::entry('Anna', 'Li', null, '2026-01-02 10:00:00'),
        ]);

        self::assertSame(['Anna L.', 'Anna L. (2)'], $labels);
        foreach ($labels as $label) {
            self::assertStringNotContainsString('Li', $label);
        }
    }

    public function test_collision_is_case_insensitive_and_the_custom_label_stays(): void
    {
        self::assertSame(['anna m.', 'Anna Mü.'], self::labels([self::entry('Zoe', 'Roth', 'anna m.'), self::entry('Anna', 'Müller')]));
    }

    public function test_custom_label_equal_to_a_generated_one_makes_the_generated_one_grow(): void
    {
        self::assertSame(['Anna M.', 'Anna Mü.'], self::labels([self::entry('Zoe', 'Roth', 'Anna M.'), self::entry('Anna', 'Müller')]));
    }

    public function test_multibyte_first_letter(): void
    {
        self::assertSame(['Élodie Ö.'], self::labels([self::entry('Élodie', 'Ölmez')]));
    }

    public function test_sort_uses_the_collator(): void
    {
        self::assertSame(
            ['Anna M.', 'Élodie Ö.', 'Zoe R.'],
            self::labels([self::entry('Zoe', 'Roth'), self::entry('Élodie', 'Ölmez'), self::entry('Anna', 'Müller')]),
        );
    }

    public function test_empty_input(): void
    {
        self::assertSame([], (new RosterLabeller())->label([]));
    }

    /**
     * @param list<RosterEntry> $entries
     *
     * @return list<string>
     */
    private static function labels(array $entries): array
    {
        return array_map(static fn (RosterLabel $label): string => $label->label, (new RosterLabeller())->label($entries));
    }

    /**
     * @param list<RosterLabel> $labels
     *
     * @return array<string, string>
     */
    private static function byEnrollment(array $labels): array
    {
        $byId = [];
        foreach ($labels as $label) {
            $byId[$label->enrollmentId->toRfc4122()] = $label->label;
        }

        return $byId;
    }

    private static function entry(string $firstName, string $lastName, ?string $label = null, string $joinedAt = '2026-01-01 10:00:00'): RosterEntry
    {
        return new RosterEntry(Uuid::v7(), Uuid::v7(), $firstName, $lastName, $label, new \DateTimeImmutable($joinedAt, new \DateTimeZone('UTC')));
    }
}
