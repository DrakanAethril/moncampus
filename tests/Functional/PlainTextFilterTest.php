<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The `plain_text` / `plain_text_lines` filters rendered through the real Twig environment.
 *
 * The unit test over App\Service\HtmlPlainText pins what the text becomes; what this pins is the
 * half that only exists inside a template - **that the escaper never sees an entity again**. That
 * is the whole bug: `|striptags` hands the escaper an "&" it correctly turns into "&amp;", and the
 * reader ends up looking at "S&eacute;ance" spelled out. A test on the service alone would still
 * have passed with the templates left broken.
 */
class PlainTextFilterTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    public function testTheReaderSeesTheAccentAndNotItsCode(): void
    {
        $rendered = $this->render('{{ content|plain_text }}', '<p>S&eacute;ance d&eacute;couverte</p>');

        self::assertSame('Séance découverte', $rendered);
        self::assertStringNotContainsString('&amp;', $rendered, 'The escaper must never be handed an entity to escape.');
    }

    public function testTheOldFilterIsWhatTheBugLookedLike(): void
    {
        // Kept as the counter-example: this is exactly what the cahier de texte used to print.
        self::assertSame('S&amp;eacute;ance', $this->render('{{ content|striptags }}', '<p>S&eacute;ance</p>'));
    }

    public function testTextTypedAsMarkupIsStillEscapedOnTheWayOut(): void
    {
        // Decoding entities must not become a hole: what the field held as text stays text.
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $this->render('{{ content|plain_text }}', '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'));
    }

    public function testARealTagIsRemovedRatherThanEscapedIntoView(): void
    {
        self::assertSame('alert(1)', $this->render('{{ content|plain_text }}', '<p><script>x</script>alert(1)</p>'));
    }

    public function testAnEmptyEditorFallsBackOnTheScreensOwnWording(): void
    {
        self::assertSame('rien', $this->render("{{ content|plain_text ?: 'rien' }}", '<p>&nbsp;</p>'));
    }

    public function testABodyKeepsItsParagraphsThroughNl2br(): void
    {
        self::assertSame(
            "Bonjour,<br />\n<br />\nVoici le dossier.",
            $this->render('{{ content|plain_text_lines|nl2br }}', '<p>Bonjour,</p><p>Voici le dossier.</p>'),
        );
    }

    private function render(string $template, ?string $content): string
    {
        return $this->twig->createTemplate($template)->render(['content' => $content]);
    }
}
