<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use App\Entity\User;
use App\Enum\ReleaseEntryType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Twig\Environment;
use Twig\Error\RuntimeError;

/**
 * The markup contract of the Cm:* components (templates/components/Cm/). Each screen used to write
 * this markup by hand; what is pinned here is what those screens now rely on.
 */
class CmComponentsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    protected function setUp(): void
    {
        self::bootKernel();
        // translator and router both read the current request for the locale and the base URL.
        self::getContainer()->get(RequestStack::class)->push(Request::create('/'));
    }

    public function testBreadcrumbPrependsAccueilAndMarksTheLastSegmentCurrent(): void
    {
        $crawler = $this->renderTwigComponent('Cm:Breadcrumb', ['segments' => [
            ['label' => 'Configuration', 'url' => '/settings'],
            ['label' => 'Salles', 'url' => '/settings/rooms'],
        ]])->crawler();

        $links = $crawler->filter('nav.cm-breadcrumb a');
        self::assertSame(['Accueil', 'Configuration', 'Salles'], $links->each(static fn ($a): string => $a->text()));
        self::assertSame('/', $links->eq(0)->attr('href'));
        self::assertSame('current', $links->eq(2)->attr('class'));
        self::assertNull($links->eq(1)->attr('class'));
        self::assertCount(1, $crawler->filter('svg.cm-breadcrumb__home'));
        self::assertCount(2, $crawler->filter('.sep'));
    }

    public function testBreadcrumbOfTheDashboardIsALoneCurrentAccueil(): void
    {
        $links = $this->renderTwigComponent('Cm:Breadcrumb')->crawler()->filter('a');

        self::assertCount(1, $links);
        self::assertSame('current', $links->attr('class'));
    }

    public function testBreadcrumbRefusesACallerThatStillPassesAccueil(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/prepends « Accueil » itself/');

        $this->renderTwigComponent('Cm:Breadcrumb', ['segments' => [['label' => 'Accueil', 'url' => '/']]]);
    }

    public function testTabsRenderActiveTabAndCount(): void
    {
        $crawler = $this->renderTwigComponent('Cm:Tabs', ['tabs' => [
            ['url' => '/a', 'label' => 'Campagnes', 'active' => true, 'count' => 9],
            ['url' => '/b', 'label' => 'Modèles', 'active' => false],
        ]])->crawler();

        self::assertSame('cm-tabs__tab is-active', $crawler->filter('a')->eq(0)->attr('class'));
        self::assertSame('9', $crawler->filter('.cm-tabs__count')->text());
        self::assertCount(0, $crawler->filter('.cm-tabs__action'), 'no action block, no empty wrapper');
    }

    /**
     * The action partials (settings/structure/_rooms_button.html.twig…) read the screen's own
     * variables. A block passed to a component is rendered in the caller's context - the reason it
     * is a block and not a template path handed to the component.
     */
    public function testTabsActionBlockSeesTheCallersVariables(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(<<<'TWIG'
            {% set activeTab = 'rooms' %}
            <twig:Cm:Tabs :tabs="[{url: '/a', label: 'A', active: true}]">
                <twig:block name="action"><em>{{ activeTab }}</em></twig:block>
            </twig:Cm:Tabs>
            TWIG)->render();

        self::assertStringContainsString('<div class="cm-tabs__action">', $html);
        self::assertStringContainsString('<em>rooms</em>', $html);
    }

    public function testBadgeReadsTextAndToneFromItsValue(): void
    {
        $badge = $this->renderTwigComponent('Cm:Badge', ['of' => ReleaseEntryType::Fix])->crawler()->filter('span');

        self::assertSame('cm-badge cm-badge--'.ReleaseEntryType::Fix->badgeTone()->value, $badge->attr('class'));
        self::assertNotSame('', trim($badge->text()));
        self::assertNotSame(ReleaseEntryType::Fix->labelKey(), $badge->text(), 'the label key is translated');
    }

    public function testBadgeContentReplacesTheTextButKeepsTheTone(): void
    {
        $badge = $this->renderTwigComponent('Cm:Badge', ['of' => ReleaseEntryType::Fix], 'Autre texte')->crawler()->filter('span');

        self::assertSame('Autre texte', $badge->text());
        self::assertStringContainsString('cm-badge--'.ReleaseEntryType::Fix->badgeTone()->value, (string) $badge->attr('class'));
    }

    public function testBadgeTakesAToneByHandAndMergesExtraClasses(): void
    {
        $badge = $this->renderTwigComponent('Cm:Badge', ['tone' => 'teal', 'class' => 'ms-2'], 'Brouillon')->crawler()->filter('span');

        self::assertSame('cm-badge cm-badge--teal ms-2', $badge->attr('class'));
    }

    public function testBadgeRefusesAToneTheStylesheetDoesNotDraw(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/"secondary" is not a valid backing value/');

        $this->renderTwigComponent('Cm:Badge', ['tone' => 'secondary'], 'x');
    }

    public function testButtonIsALinkWhenGivenAnHref(): void
    {
        $link = $this->renderTwigComponent('Cm:Button', ['variant' => 'outline', 'size' => 'sm', 'href' => '/export', 'data-turbo' => 'false'], 'Exporter')->crawler()->filter('a');

        self::assertSame('/export', $link->attr('href'));
        self::assertSame('cm-btn cm-btn--outline cm-btn--sm', $link->attr('class'));
        self::assertSame('false', $link->attr('data-turbo'));
        self::assertSame('Exporter', $link->text());
        self::assertNull($link->attr('type'));
    }

    public function testButtonDefaultsToANonSubmittingButton(): void
    {
        $button = $this->renderTwigComponent('Cm:Button', ['variant' => 'primary'], 'OK')->crawler()->filter('button');

        self::assertSame('button', $button->attr('type'));
        self::assertSame('cm-btn cm-btn--primary', $button->attr('class'));
    }

    public function testButtonRefusesAVariantTheStylesheetDoesNotDraw(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessageMatches('/no "secondary" variant/');

        $this->renderTwigComponent('Cm:Button', ['variant' => 'secondary'], 'x');
    }

    /**
     * The icon file carries the drawing and its stroke; the call site the size and any override.
     */
    public function testIconMergesTheCallersAttributesOverTheFile(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate('<twig:ux:icon name="folder" width="15" height="15" stroke-width="2.4" class="x"/>')->render();

        self::assertStringContainsString('viewBox="0 0 24 24"', $html);
        self::assertStringContainsString('width="15"', $html);
        self::assertStringContainsString('stroke-width="2.4"', $html);
        self::assertStringNotContainsString('stroke-width="1.8"', $html);
        self::assertStringContainsString('class="x"', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('<path d="M3 7a1', $html);
    }

    public function testActionBarPutsTheMetaLeftAndTheButtonsRight(): void
    {
        $crawler = $this->renderTwigComponent('Cm:ActionBar', [], '<button>OK</button>', ['meta' => 'Rien n\'est enregistré'])->crawler();

        self::assertSame('Rien n\'est enregistré', $crawler->filter('.cm-actionbar > span.cm-actionbar__meta')->text());
        self::assertSame('OK', $crawler->filter('.cm-actionbar > .cm-actionbar__buttons > button')->text());
    }

    public function testActionBarWithoutMetaDrawsNoEmptyMetaSpan(): void
    {
        $crawler = $this->renderTwigComponent('Cm:ActionBar', [], '<button>OK</button>')->crawler();

        self::assertCount(0, $crawler->filter('.cm-actionbar__meta'));
    }

    /**
     * « Modifié le … par … » only once somebody has actually modified the thing: an entity that has
     * never been edited carries a null lastUpdatedDate, and the bar then says nothing.
     */
    public function testActionBarSaysWhoLastModifiedOnlyAfterARealEdit(): void
    {
        $author = new User('jdupont');
        $edited = new class($author) {
            public function __construct(public User $lastUpdatedBy, public ?\DateTimeImmutable $lastUpdatedDate = new \DateTimeImmutable('2026-09-14'))
            {
            }
        };
        $meta = $this->renderTwigComponent('Cm:ActionBar', ['lastModified' => $edited], '<button>OK</button>')->crawler()->filter('.cm-actionbar__meta');
        self::assertStringContainsString('14/09/2026', $meta->text());
        self::assertStringContainsString('jdupont', $meta->text());

        $edited->lastUpdatedDate = null;
        $crawler = $this->renderTwigComponent('Cm:ActionBar', ['lastModified' => $edited], '<button>OK</button>')->crawler();
        self::assertCount(0, $crawler->filter('.cm-actionbar__meta'));
    }

    public function testSubheadEscapesItsTextAndKeepsTheCallersSpacing(): void
    {
        $head = $this->renderTwigComponent('Cm:Subhead', ['title' => 'Clés <API>', 'hint' => 'Une par agent', 'style' => 'margin-bottom: 14px'])->crawler()->filter('.cm-subhead');

        self::assertSame('Clés <API>', $head->filter('.cm-subhead__title')->text());
        self::assertSame('Une par agent', $head->filter('.cm-subhead__hint')->text());
        self::assertSame('margin-bottom: 14px', $head->attr('style'));
    }

    public function testSubheadWithAnActionGroupsTitleAndHintOnTheLeft(): void
    {
        $head = $this->renderTwigComponent('Cm:Subhead', ['title' => 'Consignes'], null, ['action' => '<button>Copier</button>'])->crawler()->filter('.cm-subhead');

        self::assertSame('Consignes', $head->filter('.cm-subhead > div > .cm-subhead__title')->text());
        self::assertSame('Copier', $head->filter('.cm-subhead > button')->text());
        self::assertCount(0, $head->filter('.cm-subhead__hint'));
    }
}
