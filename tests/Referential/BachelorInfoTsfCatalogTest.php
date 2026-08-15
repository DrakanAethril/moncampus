<?php

declare(strict_types=1);

namespace App\Tests\Referential;

use App\Referential\BachelorInfoTsfCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue is a hand transcription of a 46-page PDF, so what it can get wrong is a slip: a
 * missing bullet list, a duplicated code, a volume typed with the wrong digit. These pin the shape
 * and the totals against the source document rather than the code.
 *
 * @phpstan-import-type SkillDefinition from BachelorInfoTsfCatalog
 */
class BachelorInfoTsfCatalogTest extends TestCase
{
    private BachelorInfoTsfCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new BachelorInfoTsfCatalog();
    }

    public function testHoldsTheSevenBlocksOfTheDocument(): void
    {
        $groups = $this->catalog->groups();

        self::assertCount(7, $groups);
        self::assertSame(
            ['CDA', 'CDA', 'CDA', null, 'AIS', 'AIS', 'AIS'],
            array_column($groups, 'optionShortName'),
        );
    }

    public function testHoldsTheTwentyThreeFichesOfTheDocument(): void
    {
        self::assertCount(23, $this->skills());
    }

    /** 13 fiches on the CDA side (11 CCP + 2 cross-cutting), 10 on the AIS side. */
    public function testSplitsElevenCdaTenAisAndTwoCrossCutting(): void
    {
        $counts = [];
        foreach ($this->catalog->groups() as $group) {
            $counts[$group['optionShortName'] ?? 'transverse'] = ($counts[$group['optionShortName'] ?? 'transverse'] ?? 0) + \count($group['skills']);
        }

        self::assertSame(['CDA' => 11, 'transverse' => 2, 'AIS' => 10], $counts);
    }

    /**
     * The hour totals of the source document. A single mistyped volume moves one of these, which is
     * the cheapest possible check on 23 hand-copied numbers.
     */
    public function testTotalsTheDocumentsHours(): void
    {
        $byOption = [];
        foreach ($this->catalog->groups() as $group) {
            $key = $group['optionShortName'] ?? 'transverse';
            foreach ($group['skills'] as $skill) {
                $byOption[$key] = ($byOption[$key] ?? 0.0) + (float) $skill['volumeHours'];
            }
        }

        self::assertSame(380.0, $byOption['CDA']);
        self::assertSame(380.0, $byOption['AIS']);
        self::assertSame(75.0, $byOption['transverse']);
    }

    public function testEveryFicheCarriesItsFourContentBlocks(): void
    {
        foreach ($this->skills() as $skill) {
            $where = $skill['code'].' '.$skill['label'];

            self::assertNotSame('', trim($skill['occupationDescription']), "Description métier manquante : $where");
            self::assertNotEmpty($skill['knowledge'], "Connaissances manquantes : $where");
            self::assertNotEmpty($skill['activities'], "Activités manquantes : $where");
            self::assertNotEmpty($skill['performanceCriteria'], "Critères de performance manquants : $where");
        }
    }

    public function testEveryFicheCarriesItsOrganisationLine(): void
    {
        foreach ($this->skills() as $skill) {
            $where = $skill['code'].' '.$skill['label'];

            self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $skill['volumeHours'], "Volume mal formé : $where");
            self::assertNotSame('', trim($skill['teachingPeriod']), "Période manquante : $where");
            self::assertNotSame('', trim($skill['teacher']), "Intervenant manquant : $where");
        }
    }

    /** C.1 to C.11 on the CDA side and C.1 to C.10 on the AIS side: the code repeats across the two. */
    public function testCodesAreUniqueWithinEachCertification(): void
    {
        /** @var array<string, list<string>> $seen */
        $seen = [];
        foreach ($this->catalog->groups() as $group) {
            $key = $group['optionShortName'] ?? 'transverse';
            foreach ($group['skills'] as $skill) {
                $seen[$key][] = $skill['code'];
            }
        }

        foreach ($seen as $key => $codes) {
            self::assertSame($codes, array_values(array_unique($codes)), "Code en double du côté $key");
        }
    }

    public function testLabelsAreUniqueAcrossTheWholeReferential(): void
    {
        $labels = array_column($this->skills(), 'label');

        self::assertSame($labels, array_values(array_unique($labels)));
    }

    /** One certification per certifying option - the reason ProgramCertification is keyed by option. */
    public function testDeclaresOneCertificationPerOption(): void
    {
        $certifications = $this->catalog->certifications();

        self::assertCount(2, $certifications);
        self::assertSame(['AIS', 'CDA'], array_column($certifications, 'optionShortName'));

        foreach ($certifications as $certification) {
            self::assertSame('titre_pro', $certification['kind']);
            // The source document names no RNCP code; inventing one would be worse than an empty
            // field an admin fills in.
            self::assertNull($certification['rncpCode']);
        }
    }

    /** @return list<SkillDefinition> */
    private function skills(): array
    {
        $skills = [];
        foreach ($this->catalog->groups() as $group) {
            foreach ($group['skills'] as $skill) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }
}
