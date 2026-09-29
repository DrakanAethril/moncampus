<?php

declare(strict_types=1);

namespace App\Tests\Service\Rncp;

use App\Service\Rncp\ReferentialLabelMatcher;
use App\Service\Rncp\RncpCompetencyListParser;
use App\Service\Rncp\RncpFicheReader;
use PHPUnit\Framework\TestCase;

/**
 * The fixture is an extract of France compétences' export of 2026-09-29 (Licence Ouverte 2.0): the
 * BTS SIO fiche RNCP40792 and the one it replaces, RNCP35340, as the export writes them.
 */
final class RncpFicheReaderTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/rncp/export-fiches-rncp-extract.xml';

    public function testReadsTheBtsSioFicheWithItsFiveBlocks(): void
    {
        $fiche = (new RncpFicheReader())->readFromUri(self::FIXTURE, 'rncp40792');

        self::assertNotNull($fiche);
        self::assertSame('RNCP40792', $fiche['numero']);
        self::assertStringStartsWith('Services informatiques aux organisations', $fiche['intitule']);
        self::assertSame('Niveau 5', $fiche['niveau']);
        self::assertSame('BTS', $fiche['abrege']);
        self::assertTrue($fiche['actif']);
        self::assertSame('2025-09-01', $fiche['dateEffet']);
        self::assertSame('2028-08-31', $fiche['dateFinEnregistrement']);
        self::assertSame(['RNCP35340'], $fiche['anciennes']);
        self::assertStringContainsString('ENSEIGNEMENT SUPERIEUR', $fiche['certificateurs'][0]);
        self::assertCount(5, $fiche['blocs']);

        $bloc1 = $fiche['blocs'][0];
        self::assertSame('RNCP40792BC01', $bloc1['code']);
        self::assertSame('Support et mise à disposition de services informatiques', $bloc1['libelle']);
        self::assertSame([
            'Gérer le patrimoine informatique',
            'Répondre aux incidents et aux demandes d’assistance et d’évolution',
            'Développer la présence en ligne de l’organisation',
            'Travailler en mode projet',
            'Mettre à disposition des utilisateurs un service informatique',
            'Organiser son développement professionnel',
        ], array_column($bloc1['competences'], 'label'));

        // 22 savoir-faire in bloc 1 - the very ones the 2026 E5 template lists under its columns.
        self::assertSame(22, array_sum(array_map(static fn (array $c): int => \count($c['skills']), $bloc1['competences'])));
        self::assertSame('Recenser et identifier les ressources numériques', $bloc1['competences'][0]['skills'][0]);

        self::assertCount(3, $fiche['blocs'][1]['competences']);
        self::assertStringContainsString('Option A', $fiche['blocs'][1]['libelle']);
        self::assertCount(3, $fiche['blocs'][2]['competences']);
        self::assertStringContainsString('Option B', $fiche['blocs'][2]['libelle']);
    }

    public function testAnUnknownFicheIsNull(): void
    {
        self::assertNull((new RncpFicheReader())->readFromUri(self::FIXTURE, 'RNCP00000'));
    }

    public function testReadsThroughAZipWithoutInflatingIt(): void
    {
        $zipPath = sys_get_temp_dir().'/rncp-test-'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFile(self::FIXTURE, 'export_fiches_RNCP_V4_1_2026-09-29.xml');
        $zip->close();

        try {
            $fiche = (new RncpFicheReader())->readFromZip($zipPath, 'RNCP35340');
            self::assertNotNull($fiche);
            self::assertSame('RNCP35340', $fiche['numero']);
        } finally {
            unlink($zipPath);
        }
    }

    public function testTheParserReadsAHandTypedList(): void
    {
        $parsed = (new RncpCompetencyListParser())->parse("* Gérer les données\n  o Exploiter des données\n  o Concevoir une base.\n* Travailler en équipe");

        self::assertSame([
            ['label' => 'Gérer les données', 'skills' => ['Exploiter des données', 'Concevoir une base']],
            ['label' => 'Travailler en équipe', 'skills' => []],
        ], $parsed);
    }

    public function testAnUnrecognisedTextIsNotGuessed(): void
    {
        self::assertSame([], (new RncpCompetencyListParser())->parse('Le candidat est capable de gérer un parc.'));
    }

    public function testLabelsMatchAcrossTypography(): void
    {
        self::assertTrue(ReferentialLabelMatcher::same('Répondre aux incidents et aux demandes d’assistance', 'repondre aux incidents et aux demandes d\'assistance'));
        self::assertFalse(ReferentialLabelMatcher::same('Gérer les données', 'Gérer le patrimoine informatique'));
    }

    public function testTheWatchFindsTheFicheThatReplacesAnother(): void
    {
        $reader = new RncpFicheReader();

        self::assertSame(['RNCP40792'], $reader->replacing(self::FIXTURE, 'RNCP35340'));
        self::assertSame([], $reader->replacing(self::FIXTURE, 'RNCP40792'));
    }
}
