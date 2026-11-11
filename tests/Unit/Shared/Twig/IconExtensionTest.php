<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Twig;

use App\Shared\Twig\IconExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;

final class IconExtensionTest extends TestCase
{
    private IconExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new IconExtension(new Packages(new Package(new EmptyVersionStrategy())));
    }

    public function test_decorative_icon_is_hidden_from_assistive_technology(): void
    {
        $html = $this->extension->icon('chevron-right');

        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('focusable="false"', $html);
        self::assertStringContainsString('href="icons/sprite.svg#chevron-right"', $html);
        self::assertStringContainsString('class="icon"', $html);
        self::assertStringNotContainsString('role="img"', $html);
    }

    public function test_labelled_icon_has_an_escaped_accessible_name(): void
    {
        $html = $this->extension->icon('close', 'Close "this" <now>', 'icon--20');

        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('aria-label="Close &quot;this&quot; &lt;now&gt;"', $html);
        self::assertStringContainsString('class="icon icon--20"', $html);
        self::assertStringNotContainsString('aria-hidden', $html);
    }

    public function test_invalid_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->extension->icon('Bad_Name');
    }
}
