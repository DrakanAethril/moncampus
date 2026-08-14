<?php

declare(strict_types=1);

namespace App\Tests\Service\AlternanceImport;

use App\Service\AlternanceImport\EnterpriseAddress;
use PHPUnit\Framework\TestCase;

/**
 * Turning the export's postal block into Enterprise::$address and Enterprise::$city.
 *
 * The blocks below are real ones. The one that matters is testKeepsALineThatOnlyStartsWithTheName:
 * dropping the first line unconditionally is the obvious shortcut, and it silently deletes the
 * street of every company whose address happens to open with its own name.
 */
class EnterpriseAddressTest extends TestCase
{
    private EnterpriseAddress $address;

    protected function setUp(): void
    {
        $this->address = new EnterpriseAddress();
    }

    public function testDropsTheLineRepeatingTheCompanyName(): void
    {
        $raw = "EVA TEAM\n37, route de Poulenat\n87220-BOISSEUIL";

        self::assertSame("37, route de Poulenat\n87220-BOISSEUIL", $this->address->postalAddress($raw, 'EVA TEAM'));
    }

    public function testKeepsALineThatOnlyStartsWithTheName(): void
    {
        $raw = "CABINET LEPETIT ARVID 155 rue François Perrin\n87000-LIMOGES";

        self::assertSame($raw, $this->address->postalAddress($raw, 'CABINET LEPETIT ARVID'));
    }

    public function testKeepsEveryOtherLineIncludingTheComplement(): void
    {
        $raw = "GAMAC\nParc d'Activités La Croisière\nCentre d'Affaires Arzana bât. B\n23300-ST MAURICE LA SOUTERRAINE";

        self::assertSame(
            "Parc d'Activités La Croisière\nCentre d'Affaires Arzana bât. B\n23300-ST MAURICE LA SOUTERRAINE",
            $this->address->postalAddress($raw, 'GAMAC'),
        );
    }

    public function testReadsTheTownOffThePostcodeLine(): void
    {
        self::assertSame('BOISSEUIL', $this->address->city("EVA TEAM\n37, route de Poulenat\n87220-BOISSEUIL"));
        self::assertSame('BORDEAUX CEDEX', $this->address->city("DISI SUD-OUEST\n2 rue Jules Ferry\n33090-BORDEAUX CEDEX"));
        self::assertSame('LIMOGES', $this->address->city("X\n1 rue Y\n87000 LIMOGES"));
    }

    public function testHasNoTownWhenTheBlockCarriesNoPostcode(): void
    {
        self::assertNull($this->address->city("EVA TEAM\n37, route de Poulenat"));
    }

    public function testAnEmptyBlockYieldsNothing(): void
    {
        self::assertNull($this->address->postalAddress('', 'EVA TEAM'));
        self::assertNull($this->address->postalAddress("EVA TEAM\n", 'EVA TEAM'));
    }
}
