<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class StyleguideTest extends WebTestCase
{
    private const SECTIONS = ['tokens', 'type', 'icons', 'focus', 'button', 'badge', 'balance', 'card', 'list-row', 'page-header', 'avatar', 'alert', 'empty-state', 'form', 'table', 'dialog', 'toast', 'tab-bar', 'wordmark'];

    public function test_styleguide_renders_every_section_without_inline_styles(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_styleguide');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('body.layout-teacher');
        foreach (self::SECTIONS as $section) {
            self::assertSelectorExists(\sprintf('section#sg-%1$s[data-component="%1$s"] h2', $section));
        }

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString(' style="', $html);
        self::assertGreaterThan(0, $crawler->filter('use[href]')->count());
    }

    public function test_balance_section_shows_the_s9_labels(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_styleguide');

        $balance = $crawler->filter('[data-component="balance"]')->text();
        self::assertStringContainsString('Renewal needed', $balance);
        self::assertStringContainsString('Low', $balance);
        self::assertStringContainsString('3 lessons outstanding', $balance);
        self::assertStringContainsString('10 lessons left', $balance);
        self::assertSelectorExists('[data-component="balance"] .pass[role="img"][aria-label="2 of 10 lessons left"]');
    }

    public function test_demo_form_ties_errors_and_labels_to_its_fields(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_styleguide');
        $form = $crawler->filter('[data-component="form"] form');

        $invalid = $form->filter('input[aria-invalid="true"][aria-describedby]');
        self::assertCount(1, $invalid);
        $describedBy = explode(' ', (string) $invalid->attr('aria-describedby'));
        $described = implode(' ', array_map(
            static fn (string $id): string => $crawler->filter('#'.$id)->text(),
            $describedBy,
        ));
        self::assertStringContainsString('Enter a full email address.', $described);
        self::assertSelectorExists('[data-component="form"] .alert--danger[role="alert"]');
        self::assertSelectorExists('[data-component="form"] button[data-password-toggle][hidden]');

        $controls = $form->filter('input, select, textarea');
        self::assertGreaterThan(5, $controls->count());
        foreach ($controls as $control) {
            self::assertInstanceOf(\DOMElement::class, $control);
            $id = $control->getAttribute('id');
            $labelled = '' !== $id && $form->filter(\sprintf('label[for="%s"]', $id))->count() > 0;
            $inLegendedFieldset = false;
            for ($node = $control->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
                if ('fieldset' === $node->nodeName) {
                    foreach ($node->childNodes as $child) {
                        $inLegendedFieldset = $inLegendedFieldset || ($child instanceof \DOMElement && 'legend' === $child->nodeName);
                    }
                }
            }
            self::assertTrue($labelled || $inLegendedFieldset, \sprintf('Control "%s" has no label and no fieldset legend.', $id));
        }
    }

    public function test_every_used_icon_exists_in_the_sprite(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_styleguide');

        $sprite = (string) file_get_contents(\dirname(__DIR__, 3).'/assets/icons/sprite.svg');
        foreach ($crawler->filter('use[href]') as $use) {
            self::assertInstanceOf(\DOMElement::class, $use);
            $fragment = (string) parse_url($use->getAttribute('href'), \PHP_URL_FRAGMENT);
            self::assertStringContainsString(\sprintf('<symbol id="%s"', $fragment), $sprite);
        }
    }

    public function test_flashes_render_in_the_flash_region(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_styleguide?flashes=1');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.flash-region > *'));
        self::assertSelectorExists('.flash-region .alert--danger[role="alert"]');
        self::assertSelectorTextContains('.flash-region .alert--danger', 'Something went wrong.');
        self::assertSelectorExists('.flash-region .toast[data-toast-autohide]');
        self::assertSelectorTextContains('.flash-region .toast', 'Saved.');
        self::assertSelectorExists('.flash-region .toast button[data-toast-dismiss][aria-label="Dismiss"]');
        self::assertSelectorExists('.flash-region .toast-stack > .toast');
    }

    public function test_every_table_cell_has_a_label(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_styleguide');

        $cells = $crawler->filter('[data-component="table"] td');
        self::assertGreaterThan(0, $cells->count());
        foreach ($cells as $cell) {
            self::assertInstanceOf(\DOMElement::class, $cell);
            self::assertNotSame('', trim($cell->getAttribute('data-label')), 'A table cell has no data-label.');
        }
        self::assertSelectorExists('[data-component="table"] table caption');
    }

    public function test_dialogs_are_labelled_and_every_trigger_targets_one(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/_styleguide');

        $dialogs = $crawler->filter('[data-component="dialog"] dialog');
        self::assertGreaterThanOrEqual(2, $dialogs->count());
        foreach ($dialogs as $dialog) {
            self::assertInstanceOf(\DOMElement::class, $dialog);
            $labelledBy = $dialog->getAttribute('aria-labelledby');
            self::assertNotSame('', $labelledBy);
            self::assertCount(1, $crawler->filter('#'.$labelledBy));
        }
        self::assertSelectorExists('dialog.dialog--sheet');

        $triggers = $crawler->filter('[data-dialog-open]');
        self::assertGreaterThanOrEqual(2, $triggers->count());
        foreach ($triggers as $trigger) {
            self::assertInstanceOf(\DOMElement::class, $trigger);
            $target = $trigger->getAttribute('data-dialog-open');
            self::assertCount(1, $crawler->filter(\sprintf('dialog#%s', $target)), \sprintf('No dialog "%s".', $target));
        }
        self::assertSelectorExists('form[method="get"] button[type="submit"][data-dialog-open="sg-dialog-close-checkin"]');
        self::assertSelectorExists('#sg-dialog-close-checkin button[data-dialog-confirm]');
    }

    public function test_toast_with_an_action_does_not_hide_by_itself(): void
    {
        $client = self::createClient();
        $client->request('GET', '/_styleguide');

        self::assertSelectorExists('#sg-toast-undo.toast');
        self::assertSelectorNotExists('#sg-toast-undo[data-toast-autohide]');
        self::assertSelectorExists('#sg-toast-undo .btn--toast-action.hit-target');
        self::assertSelectorExists('#sg-toast-undo button[data-toast-dismiss][hidden]');
    }
}
