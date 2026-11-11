<?php

declare(strict_types=1);

namespace App\Shared\Twig;

use Symfony\Component\Asset\Packages;
use Twig\Attribute\AsTwigFunction;

/**
 * Prints one symbol of assets/icons/sprite.svg: {{ icon('chevron-right') }} or {{ icon('close', 'Close'|trans) }}.
 */
final class IconExtension
{
    private const NAME_PATTERN = '/^[a-z0-9-]+$/';

    public function __construct(private readonly Packages $packages)
    {
    }

    #[AsTwigFunction('icon', isSafe: ['html'])]
    public function icon(string $name, ?string $label = null, string $class = ''): string
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid icon name "%s": use the sprite id, e.g. "chevron-right".', $name));
        }

        $classes = trim('icon '.$class);
        $a11y = null === $label
            ? 'aria-hidden="true"'
            : \sprintf('role="img" aria-label="%s"', $this->escape($label));
        $href = $this->packages->getUrl('icons/sprite.svg').'#'.$name;

        return \sprintf(
            '<svg class="%s" %s focusable="false"><use href="%s"></use></svg>',
            $this->escape($classes),
            $a11y,
            $this->escape($href),
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
