<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Renders a URL as an inline SVG QR code (TEC-5, S6): 400 units with a 4-module quiet zone,
 * error correction L. The XML declaration and the root's width/height are removed so the
 * code can be embedded in HTML and sized by CSS through its viewBox.
 */
final class QrCodeRenderer
{
    private const SIZE = 400;
    private const MARGIN = 4;

    public function svg(string $url): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::SIZE, self::MARGIN),
            new SvgImageBackEnd(),
        ));

        $svg = (string) preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', $writer->writeString($url));

        return (string) preg_replace_callback(
            '/<svg\b[^>]*>/',
            static fn (array $match): string => (string) preg_replace('/\s(?:width|height)="[^"]*"/', '', $match[0]),
            $svg,
            1,
        );
    }
}
