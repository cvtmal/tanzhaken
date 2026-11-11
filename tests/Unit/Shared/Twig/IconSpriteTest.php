<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use PHPUnit\Framework\TestCase;

final class IconSpriteTest extends TestCase
{
    private const SPRITE = __DIR__.'/../../../../assets/icons/sprite.svg';

    private const IDS = [
        'add', 'arrow-back', 'arrow-forward', 'calendar-month', 'calendar-today', 'check', 'check-circle',
        'chevron-right', 'close', 'confirmation-number', 'edit', 'error', 'expand-more', 'fullscreen', 'group',
        'history', 'home', 'info', 'location-on', 'lock', 'more-horiz', 'person', 'person-add', 'qr-code-2',
        'remove', 'repeat', 'schedule', 'search', 'timer', 'undo', 'visibility', 'warning',
        'home-fill', 'calendar-month-fill', 'group-fill', 'person-fill', 'check-circle-fill',
    ];

    public function test_sprite_holds_every_expected_symbol_with_the_material_view_box(): void
    {
        $svg = simplexml_load_file(self::SPRITE);
        self::assertNotFalse($svg);
        $svg->registerXPathNamespace('svg', 'http://www.w3.org/2000/svg');

        $symbols = $svg->xpath('//svg:symbol');
        self::assertIsArray($symbols);

        $ids = [];
        foreach ($symbols as $symbol) {
            $ids[] = (string) $symbol['id'];
            self::assertSame('0 -960 960 960', (string) $symbol['viewBox'], (string) $symbol['id']);
        }

        $expected = self::IDS;
        sort($expected);
        sort($ids);
        self::assertSame($expected, $ids);
    }
}
