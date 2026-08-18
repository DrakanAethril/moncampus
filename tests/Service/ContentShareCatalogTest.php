<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContentShareCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue's search, and the trap it is built around
 * (design/validated/content-sharing-between-teachers.md, "The catalogue, and a trap in it").
 *
 * Niveau / Option / Bloc are a **private vocabulary per teacher** (App\Entity\AbstractLibraryTag):
 * two colleagues who both typed « SIO1 » own two different rows. So the catalogue searches the tag
 * **labels as text**, alongside the title - and it must never offer a facet select built from the
 * reader's own tags, which would silently hide every colleague's séquence whose label differs by a
 * space. A free-text search that finds too much is recoverable; a select that finds nothing looks
 * like an empty catalogue.
 */
class ContentShareCatalogTest extends TestCase
{
    private ContentShareCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new ContentShareCatalog();
    }

    public function testAnEmptyQueryMatchesEverything(): void
    {
        self::assertTrue($this->catalog->matches('Le modèle OSI par la pratique', ''));
        self::assertTrue($this->catalog->matches('', '   '));
    }

    public function testEveryTermMustBeFound(): void
    {
        $haystack = 'Le modèle OSI par la pratique SIO 1 B1.2 C. Roussel';

        self::assertTrue($this->catalog->matches($haystack, 'osi pratique'));
        self::assertFalse($this->catalog->matches($haystack, 'osi vlan'));
    }

    /** « SIO 1 » typed by one teacher and « sio1 » by another are the same thing to a reader. */
    public function testTheSearchIgnoresCaseAndAccents(): void
    {
        self::assertTrue($this->catalog->matches('Le modèle OSI', 'MODELE'));
        self::assertTrue($this->catalog->matches('Câblage et certification', 'cablage'));
        self::assertTrue($this->catalog->matches('Première année', 'PREMIÈRE'));
    }

    /** The point of searching the label and not the identifier. */
    public function testATagLabelIsSearchableLikeATitle(): void
    {
        $haystack = $this->catalog->haystack(['Câblage et certification — TP noté', 'sio1', 'SISR', 'Y. Ferreira']);

        self::assertTrue($this->catalog->matches($haystack, 'sisr'));
        self::assertTrue($this->catalog->matches($haystack, 'sio1 câblage'));
        self::assertTrue($this->catalog->matches($haystack, 'ferreira'));
    }

    public function testTheHaystackDropsWhatIsEmpty(): void
    {
        self::assertSame('Le modèle OSI · C. Roussel', $this->catalog->haystack(['Le modèle OSI', null, '', '  ', 'C. Roussel']));
    }
}
