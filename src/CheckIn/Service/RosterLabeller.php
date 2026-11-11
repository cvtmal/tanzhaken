<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use App\CheckIn\ReadModel\RosterEntry;

/**
 * Public roster labels (PRD §4.5, plan P2). A label never shows a full last name:
 *
 * 1. Base: the trimmed enrollment label when non-empty (custom); else `First L.`, the trimmed
 *    first name, a space, the first character of the trimmed last name and a dot (no last
 *    name: the first name alone).
 * 2. While labels collide case-insensitively, every generated label of a colliding set that can
 *    still grow takes one more last-name character, up to min(3, length − 1), at least 1.
 *    Custom labels never change.
 * 3. Labels still equal get ` (2)`, ` (3)` … by joinedAt, then enrollment id; the first keeps its label.
 * 4. Sorted with the `en` collator on the final label, ties by enrollment id.
 */
final class RosterLabeller
{
    private const MAX_PREFIX = 3;

    /**
     * @param list<RosterEntry> $entries
     *
     * @return list<RosterLabel>
     */
    public function label(array $entries): array
    {
        /** @var array<int, string> $labels */
        $labels = [];
        /** @var array<int, array{first: string, last: string, length: int, cap: int}> $generated */
        $generated = [];

        foreach ($entries as $i => $entry) {
            $custom = trim($entry->publicCheckinLabel ?? '');
            if ('' !== $custom) {
                $labels[$i] = $custom;
                continue;
            }

            $first = trim($entry->firstName);
            $last = trim($entry->lastName);
            if ('' === $last) {
                $labels[$i] = $first;
                continue;
            }

            $generated[$i] = ['first' => $first, 'last' => $last, 'length' => 1, 'cap' => max(1, min(self::MAX_PREFIX, mb_strlen($last) - 1))];
            $labels[$i] = self::generatedLabel($first, $last, 1);
        }

        do {
            $grew = false;
            foreach (self::collisions($labels) as $set) {
                foreach ($set as $i) {
                    if (!isset($generated[$i]) || $generated[$i]['length'] >= $generated[$i]['cap']) {
                        continue;
                    }
                    ++$generated[$i]['length'];
                    $labels[$i] = self::generatedLabel($generated[$i]['first'], $generated[$i]['last'], $generated[$i]['length']);
                    $grew = true;
                }
            }
        } while ($grew);

        foreach (self::collisions($labels) as $set) {
            usort($set, static fn (int $a, int $b): int => [$entries[$a]->joinedAt, $entries[$a]->enrollmentId->toRfc4122()]
                <=> [$entries[$b]->joinedAt, $entries[$b]->enrollmentId->toRfc4122()]);
            foreach (\array_slice($set, 1) as $position => $i) {
                $labels[$i] .= \sprintf(' (%d)', $position + 2);
            }
        }

        $result = [];
        foreach ($entries as $i => $entry) {
            $result[] = new RosterLabel($entry->enrollmentId, $labels[$i]);
        }

        $collator = new \Collator('en');
        usort($result, static fn (RosterLabel $a, RosterLabel $b): int => ((int) $collator->compare($a->label, $b->label))
            ?: strcmp($a->enrollmentId->toRfc4122(), $b->enrollmentId->toRfc4122()));

        return $result;
    }

    private static function generatedLabel(string $first, string $last, int $length): string
    {
        return trim($first.' '.mb_substr($last, 0, $length).'.');
    }

    /**
     * The sets of entry indexes whose labels are equal case-insensitively, two or more each.
     *
     * @param array<int, string> $labels
     *
     * @return list<list<int>>
     */
    private static function collisions(array $labels): array
    {
        $byKey = [];
        foreach ($labels as $i => $label) {
            $byKey[mb_strtolower($label)][] = $i;
        }

        return array_values(array_filter($byKey, static fn (array $set): bool => \count($set) > 1));
    }
}
