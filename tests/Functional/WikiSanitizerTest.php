<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * What survives `app.wiki_page_body`, and - the point of the test - what does not.
 *
 * The wiki's sanitizer is the widest in config/packages/html_sanitizer.yaml and the only one that
 * admits any SVG, because Mermaid stores its diagram as one. An SVG is a document that can carry
 * script, so the subtree is enumerated rather than opened, and every exclusion below is a decision
 * somebody could otherwise undo by "just adding one element".
 *
 * Driven through the real configured service rather than a hand-built sanitizer: what is being
 * tested is the YAML, not the component.
 */
class WikiSanitizerTest extends KernelTestCase
{
    private function sanitizer(): HtmlSanitizerInterface
    {
        self::bootKernel();

        /** @var HtmlSanitizerInterface $sanitizer */
        $sanitizer = self::getContainer()->get('html_sanitizer.sanitizer.app.wiki_page_body');

        return $sanitizer;
    }

    // --- What the editor produces has to survive ------------------------------------------

    public function testAMermaidDiagramSurvivesWithItsSourceAndItsShapes(): void
    {
        $html = $this->sanitizer()->sanitize(
            '<div class="cm-mermaid" data-mermaid="graph TD; A--&gt;B;">'
            .'<svg id="m1" class="flowchart" viewBox="0 0 100 50" role="graphics-document">'
            .'<style>#m1 .node rect{fill:#fff}</style>'
            .'<defs><marker id="arrow" viewBox="0 0 10 10" refX="5" orient="auto"><path d="M0,0 L10,5 L0,10"/></marker></defs>'
            .'<g class="node" transform="translate(10,10)"><rect x="0" y="0" width="40" height="20" rx="3" fill="#fff" stroke="#333"/>'
            .'<text x="20" y="14" text-anchor="middle" class="nodeLabel">A</text></g>'
            .'<path class="edge" d="M50,20 L80,20" stroke="#333" marker-end="url(#arrow)"/>'
            .'</svg></div>',
        );

        self::assertStringContainsString('data-mermaid="graph TD; A--&gt;B;"', $html);
        foreach (['<svg', '<style', '<defs', '<marker', '<rect', '<text', '<path', 'viewBox=', 'transform=', 'marker-end='] as $expected) {
            self::assertStringContainsString($expected, $html, $expected.' should have survived');
        }
    }

    public function testAKatexFormulaSurvivesWithItsSourceAndItsMarkup(): void
    {
        $html = $this->sanitizer()->sanitize(
            '<span class="cm-katex" data-katex="e^{i\pi}+1=0"><span class="katex"><span class="katex-mathml">…</span></span></span>',
        );

        self::assertStringContainsString('data-katex="e^{i\pi}+1=0"', $html);
        self::assertStringContainsString('class="katex"', $html);
    }

    public function testCalloutsCodeSamplesAndAccordionsSurvive(): void
    {
        $html = $this->sanitizer()->sanitize(
            '<div class="cm-callout cm-callout--warning"><p>Attention</p></div>'
            .'<pre class="language-php"><code class="language-php">echo 1;</code></pre>'
            .'<details><summary>Plus</summary><p>Détail</p></details>',
        );

        self::assertStringContainsString('class="cm-callout cm-callout--warning"', $html);
        self::assertStringContainsString('class="language-php"', $html);
        self::assertStringContainsString('<details>', $html);
        self::assertStringContainsString('<summary>', $html);
    }

    public function testHeadingAnchorsSurviveSoTheOutlineCanLinkToThem(): void
    {
        $html = $this->sanitizer()->sanitize('<h2 id="adressage-ip">Adressage IP</h2><a id="repere"></a>');

        self::assertStringContainsString('id="adressage-ip"', $html);
        self::assertStringContainsString('id="repere"', $html);
    }

    // --- What must never survive ----------------------------------------------------------

    public function testScriptIsDroppedInsideAnSvgAsWellAsOutsideOne(): void
    {
        $html = $this->sanitizer()->sanitize('<p>Avant</p><script>alert(1)</script><svg><script>alert(2)</script><rect/></svg>');

        self::assertStringNotContainsString('alert(', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testForeignObjectIsRefusedSoNoHtmlHidesInsideAPicture(): void
    {
        // Which is exactly why the editor configures Mermaid with htmlLabels:false - its labels
        // come back as <text> instead, so refusing this costs nothing.
        $html = $this->sanitizer()->sanitize('<svg><foreignObject width="10" height="10"><div>caché</div></foreignObject></svg>');

        self::assertStringNotContainsString('foreignObject', $html);
    }

    public function testNoLinkTargetSurvivesInsideADiagram(): void
    {
        $html = $this->sanitizer()->sanitize(
            '<svg><use href="#x"/><a href="https://exemple.test"><rect/></a><path d="M0,0" xlink:href="https://exemple.test"/></svg>',
        );

        self::assertStringNotContainsString('xlink:href', $html);
        self::assertStringNotContainsString('exemple.test', $html);
        self::assertStringNotContainsString('<use', $html);
    }

    public function testEventHandlersAreDroppedEverywhere(): void
    {
        $html = $this->sanitizer()->sanitize(
            '<p onclick="alert(1)">x</p><svg onload="alert(2)"><rect onmouseover="alert(3)"/></svg><img src="/a.png" onerror="alert(4)">',
        );

        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('onload', $html);
        self::assertStringNotContainsString('onmouseover', $html);
        self::assertStringNotContainsString('onerror', $html);
    }

    public function testThereIsNoVideoInAWiki(): void
    {
        // The decision is enforced here rather than only in the toolbar: a toolbar decides what is
        // easy to insert, a sanitizer decides what survives.
        $html = $this->sanitizer()->sanitize(
            '<iframe src="https://exemple.test"></iframe><video src="/a.mp4"></video>'
            .'<embed src="/a.swf"><object data="/a.pdf"></object>',
        );

        foreach (['iframe', 'video', 'embed', '<object'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, $forbidden.' must never survive');
        }
    }

    public function testJavascriptUrlsAreDropped(): void
    {
        $html = $this->sanitizer()->sanitize('<a href="javascript:alert(1)">clic</a>');

        self::assertStringNotContainsString('javascript:', $html);
    }
}
