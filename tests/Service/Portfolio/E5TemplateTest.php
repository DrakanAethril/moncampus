<?php

declare(strict_types=1);

namespace App\Tests\Service\Portfolio;

use App\Enum\PortfolioSetting;
use App\Service\Portfolio\E5SynthesisXlsxWriter;
use App\Service\Portfolio\E5TemplateInspector;
use App\Service\Portfolio\PortfolioSectionResolver;
use App\Service\Portfolio\PortfolioSynthesis;
use App\Service\Portfolio\Xlsx\XlsxWorkbook;
use PHPUnit\Framework\TestCase;

/**
 * The fixture is the SIEC's « EPREUVE ORALE E5 - MODELE DE TABLEAU DE SYNTHESE ANNEXE VI-1 - BTS SIO
 * 2026.xlsx », a document of the Éducation nationale kept here for the tests only (see NOTICE).
 */
final class E5TemplateTest extends TestCase
{
    use PortfolioFixtures;

    private const string TEMPLATE = __DIR__.'/../../Fixtures/portfolio/e5-synthesis-2026.xlsx';

    public function testThe2026TemplateIsAcceptedLandmarkByLandmark(): void
    {
        $sisr = $this->sisr();
        $result = (new E5TemplateInspector())->inspect(self::TEMPLATE, $this->referential($sisr), 2026);

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['warnings']);
        /** @var array{session: array{cell: string, value: int}, labels: array<string, string>, options: array<string, array{cell: string}>, columns: array<string, array{column: string}>, parts: array<int, array{title: int, first: int, last: int}>} $anchors */
        $anchors = $result['anchors'];
        self::assertSame(['cell' => 'G1', 'value' => 2026], $anchors['session']);
        self::assertSame('A3', $anchors['labels']['name']);
        self::assertSame('F3', $anchors['labels']['candidate']);
        self::assertSame('A5', $anchors['labels']['url']);
        self::assertSame('G4', $anchors['options']['SISR']['cell']);
        self::assertSame(['C', 'D', 'E', 'F', 'G', 'H'], array_values(array_map(static fn (array $c): string => $c['column'], $anchors['columns'])));
        self::assertSame(['title' => 8, 'first' => 9, 'last' => 18], $anchors['parts'][1]);
        self::assertSame(['title' => 19, 'first' => 20, 'last' => 26], $anchors['parts'][2]);
        self::assertSame(['title' => 27, 'first' => 28, 'last' => 34], $anchors['parts'][3]);
    }

    public function testAnotherSessionIsAWarning(): void
    {
        $result = (new E5TemplateInspector())->inspect(self::TEMPLATE, $this->referential($this->sisr()), 2027);

        self::assertSame([], $result['errors']);
        self::assertSame('portfolioTemplateSessionMismatch', $result['warnings'][0]['key']);
    }

    public function testAColumnTheTemplateDoesNotHaveIsARefusalNamingIt(): void
    {
        $referential = $this->referential($this->sisr());
        $referential->getSynthesisBlock()?->getCompetencies()->get(2)?->setLabel('Sécuriser les usages');

        $result = (new E5TemplateInspector())->inspect(self::TEMPLATE, $referential, 2026);

        self::assertSame([['key' => 'portfolioTemplateMissingColumn', 'detail' => 'Sécuriser les usages']], $result['errors']);
    }

    public function testTheWriterFillsTheTemplateAndAddsRowsWhenAPartOverflows(): void
    {
        $sisr = $this->sisr();
        $referential = $this->referential($sisr);
        $anchors = (new E5TemplateInspector())->inspect(self::TEMPLATE, $referential, 2026)['anchors'];
        $portfolio = $this->portfolio($referential);
        $portfolio->setCandidateNumber('02271234567')->setExternalUrl('https://lea-martin.fr');

        for ($i = 1; $i <= 12; ++$i) {
            $this->achievement($portfolio, 'TP '.$i, PortfolioSetting::Training, '2026-01-12', '2026-02-06', [0, 3], [0]);
        }
        $this->achievement($portfolio, 'Migration', PortfolioSetting::Workplace, '2026-05-19', '2026-06-27', [1, 4], [1, 4]);

        $table = PortfolioSynthesis::compose($portfolio, false, [], new PortfolioSectionResolver(), 'MARTIN Léa', 'Institution Beaupeyrat', [['label' => 'SISR', 'checked' => true], ['label' => 'SLAM', 'checked' => false]], 2026);
        $bytes = (new E5SynthesisXlsxWriter())->write(self::TEMPLATE, $anchors, $table);

        $path = sys_get_temp_dir().'/e5-'.bin2hex(random_bytes(4)).'.xlsx';
        file_put_contents($path, $bytes);

        try {
            $texts = (new XlsxWorkbook($path))->texts();
            self::assertSame('NOM et prénom : MARTIN Léa', $texts['A3']);
            self::assertSame('N° candidat : 02271234567', $texts['F3']);
            self::assertSame('Adresse URL du portfolio : https://lea-martin.fr', $texts['A5']);
            self::assertSame('☒ SISR', $texts['G4']);
            self::assertSame('▢ SLAM', $texts['H4']);
            self::assertSame('SESSION 2026', $texts['G1']);
            self::assertSame('TP 1', $texts['A9']);
            self::assertSame('X', $texts['C9']);
            self::assertArrayNotHasKey('F9', $texts, 'claimed, not retained');
            self::assertSame('TP 12', $texts['A20'], 'two rows were added to part 1');
            self::assertStringContainsString('première année', $texts['A21']);
            self::assertSame('Migration', $texts['A22']);
            self::assertSame('19/05/26 au 27/06/26', $texts['B22']);
            self::assertSame('X', $texts['D22']);
            self::assertStringContainsString('seconde année', $texts['A29']);
        } finally {
            unlink($path);
        }
    }

    public function testTheOfficialTableNeverCarriesPendingWork(): void
    {
        $referential = $this->referential($this->sisr());
        $portfolio = $this->portfolio($referential);
        $table = PortfolioSynthesis::compose($portfolio, true, [], new PortfolioSectionResolver(), 'X', null, [], 2026);

        $this->expectException(\LogicException::class);
        (new E5SynthesisXlsxWriter())->write(self::TEMPLATE, [], $table);
    }
}
