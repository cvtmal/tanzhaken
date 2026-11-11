<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Tests\Support\Twig\RawTextScanner;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Twig\Environment;

/**
 * Every user-facing string comes from a catalogue (S7); no inline style or script (S6). No baseline.
 */
final class TemplatesHaveNoRawTextTest extends KernelTestCase
{
    public function test_templates_have_no_raw_text_inline_style_or_inline_script(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $scanner = new RawTextScanner($twig);
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);
        $root = $projectDir.'/templates';

        $files = (new Finder())->files()->in($root)->name('*.twig')->sortByName();
        self::assertGreaterThan(0, \count($files));

        $violations = [];
        foreach ($files as $file) {
            $violations = [...$violations, ...$scanner->scan($file->getRelativePathname(), $file->getContents())];
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
