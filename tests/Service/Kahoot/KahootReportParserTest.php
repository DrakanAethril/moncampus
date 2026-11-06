<?php

declare(strict_types=1);

namespace App\Tests\Service\Kahoot;

use App\Service\Kahoot\KahootNote;
use App\Service\Kahoot\KahootReport;
use App\Service\Kahoot\KahootReportParser;
use App\Service\QuizCsvImportException;
use PHPUnit\Framework\TestCase;

/**
 * A Kahoot game report read back into a quiz, on the two layouts Kahoot has exported - the cells
 * are made up here, shaped on real reports, and no player of a real game appears.
 */
class KahootReportParserTest extends TestCase
{
    private const array OLD_HEADER = ['Question Number', 'Question', 'Answer 1', 'Answer 2', 'Answer 3', 'Answer 4', 'Answer 5', 'Answer 6', 'Correct Answers', 'Time Allotted to Answer (seconds)', 'Player', 'Answer', 'Correct / Incorrect', 'Correct', 'Incorrect', 'Score (points)'];
    private const array NEW_HEADER = ['Question Number', 'Question', 'Answer 1', 'Answer 2', 'Answer 3', 'Answer 4', 'Answer 5', 'Answer 6', 'Correct Answers', 'Time Allotted to Answer (seconds)', 'Player', 'Answer', 'Answer Status', 'Correct', 'Incorrect', 'Score (points)'];

    public function testTheOlderLayout(): void
    {
        $report = KahootReportParser::parse([
            'Overview' => [['CEJM - Q3.4 - Coordination'], ['Played on', '12 Dec 2024']],
            'Final Scores' => [],
            '1 Quiz' => [],
            '2 Quiz' => [],
            '3 Puzzle' => [],
            '4 Quiz' => [],
            'RawReportData Data' => [
                self::OLD_HEADER,
                // The older layout calls a question with several right answers a « Quiz » too.
                ['1 Quiz', 'Lesquels sont des mécanismes de coordination ?', 'Ajustement mutuel', 'Supervision indirecte', 'Standardisation des procédés ', '', '', '', 'Ajustement mutuel, Standardisation des procédés ', '30.0', 'Pseudo A', 'Ajustement mutuel', 'Incorrect', '0', '1', '0'],
                ['1 Quiz', 'Lesquels sont des mécanismes de coordination ?', 'Ajustement mutuel', 'Supervision indirecte', 'Standardisation des procédés ', '', '', '', 'Ajustement mutuel, Standardisation des procédés ', '30.0', 'Pseudo B', 'Ajustement mutuel', 'Incorrect', '0', '1', '0'],
                // A right answer holding a comma of its own.
                ['2 Quiz', 'L&#x27;employeur peut-il collecter ce numéro ?', 'Oui, pour la paye.', 'Non, il est interdit de le récolter', '', '', '', '', 'Non, il est interdit de le récolter', '20.0', 'Pseudo A', '', 'Incorrect', '0', '1', '0'],
                ['3 Puzzle', 'Classez les sources du droit', 'Droit communautaire ', 'Code du Travail', 'Contrat de travail', '', '', '', '', '60.0', 'Pseudo A', '', 'Incorrect', '0', '1', '0'],
                ['4 Quiz', 'Le contrat de travail est écrit.', 'Vrai', 'Faux', '', '', '', '', 'Faux', '20.0', 'Pseudo A', 'Faux', 'Correct', '1', '0', '900'],
            ],
        ], 'export.xlsx');

        self::assertSame('CEJM - Q3.4 - Coordination', $report->title);
        self::assertSame('12 Dec 2024', $report->playedOn);
        self::assertSame([], $report->notes);

        $questions = $this->questions($report->document);
        self::assertCount(4, $questions);
        self::assertSame(['label' => 'Lesquels sont des mécanismes de coordination ?', 'seconds' => 30, 'type' => 'qcm_multi', 'answers' => ['Ajustement mutuel', 'Supervision indirecte', 'Standardisation des procédés'], 'correct' => [1, 3]], $questions[0]);
        self::assertSame("L'employeur peut-il collecter ce numéro ?", $questions[1]['label'], 'the report\'s HTML entities are decoded');
        self::assertSame(['qcm', [2]], [$questions[1]['type'], $questions[1]['correct']]);
        self::assertSame(['label' => 'Classez les sources du droit', 'seconds' => 60, 'type' => 'ordre', 'answers' => ['Droit communautaire', 'Code du Travail', 'Contrat de travail'], 'correct' => [1, 2, 3]], $questions[2]);
        self::assertSame(['vrai_faux', [2]], [$questions[3]['type'], $questions[3]['correct']]);
        self::assertSame(['qcm_multi' => 1, 'qcm' => 1, 'ordre' => 1, 'vrai_faux' => 1], $report->typeCounts);
    }

    public function testTheNewerLayout(): void
    {
        $report = KahootReportParser::parse([
            'Overview' => [['CEJM - Q 5.1 - Marché du travail'], ['Played on', '4 Nov 2026']],
            '1 Multiple-select Quiz' => [],
            '2 Slider' => [
                ['CEJM - Q 5.1 - Marché du travail'],
                ['2 Slider', 'Le salaire net moyen en France en 2025 est de'],
                ['Correct answer', '2733.0'],
                ['Choice range', '0.0 to 4000.0'],
            ],
            '3 Type answer' => [],
            '4 Poll' => [],
            '5 Word cloud' => [],
            // Nobody reached this one: it is in its own tab and nowhere in the grid.
            '6 Multiple-select Quiz' => [
                ['CEJM - Q 5.1 - Marché du travail'],
                ['6 Multiple-select Quiz', 'En finance, on distingue les cycles'],
                ['Correct answers', "d'exploitation,d'investissement"],
                ['Players correct (%)', '0.00%'],
                ['Question duration', '30 seconds'],
                ['Answer summary'],
                ['Answer options', "d'exploitation", "d'investissement", 'conjoncturels'],
                ['Is answer correct?', 'Yes', 'Yes', 'No'],
            ],
            'Raw Report Data' => [
                self::NEW_HEADER,
                ['1', "L'économie d'usage recouvre", 'la location', "l'abonnement", "l'achat", '-', '-', '-', "la location,l'abonnement", '20', 'Pseudo A', "la location, l'abonnement", 'Correct', '1', '0', '950'],
                ['2', 'Le salaire net moyen en France en 2025 est de', '-', '-', '-', '-', '-', '-', '', '20', 'Pseudo A', '2733.0', 'Correct', '1', '0', '940'],
                ['2', 'Le salaire net moyen en France en 2025 est de', '-', '-', '-', '-', '-', '-', '', '20', 'Pseudo B', '2373.0', 'Correct', '1', '0', '189'],
                ['2', 'Le salaire net moyen en France en 2025 est de', '-', '-', '-', '-', '-', '-', '', '20', 'Pseudo C', '3173.0', 'Incorrect', '0', '1', '0'],
                ['3', "Pour le macro-environnement, j'utilise l'analyse", 'PESTEL', 'Pestel', 'pestel', '-', '-', '-', 'PESTEL,Pestel,pestel', '30', 'Pseudo A', 'pestel', 'Correct', '1', '0', '932'],
                ['4', 'Je vote pour le projet', 'Cendrier', 'Jeu de cartes', '-', '-', '-', '-', '', '120', 'Pseudo A', 'Cendrier', '', '0', '0', '0'],
                ['5', 'Un mot pour ce cours', '-', '-', '-', '-', '-', '-', '', '30', 'Pseudo A', 'super', '', '0', '0', '0'],
            ],
        ], 'export.xlsx');

        $questions = $this->questions($report->document);
        self::assertCount(5, $questions);

        self::assertSame(['qcm_multi', ['la location', "l'abonnement", "l'achat"], [1, 2]], [$questions[0]['type'], $questions[0]['answers'], $questions[0]['correct']], 'the « - » of unused columns are no propositions');
        self::assertSame([
            'label' => 'Le salaire net moyen en France en 2025 est de',
            'seconds' => 20,
            'type' => 'numerique',
            'answer' => 2733.0,
            'tolerance' => 400.0,
            'toleranceMode' => 'absolute',
            'decimals' => 0,
            'range' => ['min' => 0.0, 'max' => 4000.0, 'step' => 1.0],
        ], $questions[1]);
        self::assertSame(['reponse_courte', ['PESTEL']], [$questions[2]['type'], $questions[2]['answers']], 'one spelling once case is ignored');
        self::assertTrue($questions[2]['ignoreCase']);
        self::assertSame(['vote', ['Cendrier', 'Jeu de cartes'], 120], [$questions[3]['type'], $questions[3]['answers'], $questions[3]['seconds']]);
        self::assertArrayNotHasKey('correct', $questions[3]);
        self::assertSame(['En finance, on distingue les cycles', 'qcm_multi', [1, 2], 30], [$questions[4]['label'], $questions[4]['type'], $questions[4]['correct'], $questions[4]['seconds']]);

        $notes = array_map(static fn (KahootNote $note): array => [$note->kind, $note->questionNumber, $note->parameters], $report->notes);
        self::assertSame([
            [KahootNote::TOLERANCE_GUESSED, 2, ['tolerance' => '400']],
            [KahootNote::SKIPPED_TYPE, 5, ['type' => 'word cloud']],
        ], $notes);
        self::assertTrue($report->notes[1]->dropsQuestion());
        self::assertFalse($report->notes[0]->dropsQuestion());
    }

    public function testWhatTheGameHadAndTheReportDoesNotIsNamed(): void
    {
        $row = static fn (string $number): array => [$number, 'Le SI est', 'un ensemble', 'un logiciel', '-', '-', '-', '-', 'un ensemble', '20', 'Pseudo A', 'un ensemble', 'Correct', '1', '0', '900'];

        // Every block played, yet 2 and 4 left no tab and no row: slides, most likely.
        $slides = KahootReportParser::parse([
            'Overview' => [['Révisions Thème 3'], ['Played', '4 of 4']],
            '1 Quiz' => [],
            '3 Quiz' => [],
            'Raw Report Data' => [self::NEW_HEADER, $row('1'), $row('3')],
        ], 'export.xlsx');

        self::assertSame([[KahootNote::MISSING_BLOCK, 2], [KahootNote::MISSING_BLOCK, 4]], $this->notes($slides));
        self::assertFalse($slides->notes[0]->dropsQuestion(), 'nothing of the report was left out');

        // A game stopped after 2 blocks of 4: the last two were never played.
        $stopped = KahootReportParser::parse([
            'Overview' => [['Révisions Thème 3'], ['Played', '2 of 4']],
            '1 Quiz' => [],
            '2 Quiz' => [],
            'Raw Report Data' => [self::NEW_HEADER, $row('1'), $row('2')],
        ], 'export.xlsx');

        self::assertSame([[KahootNote::NOT_PLAYED, 3], [KahootNote::NOT_PLAYED, 4]], $this->notes($stopped));
        self::assertSame(['played' => '2', 'total' => '4'], $stopped->notes[0]->parameters);

        // A report that says nothing of what was played claims nothing.
        $silent = KahootReportParser::parse([
            'Overview' => [['Révisions Thème 3']],
            '1 Quiz' => [],
            'Raw Report Data' => [self::NEW_HEADER, $row('1')],
        ], 'export.xlsx');

        self::assertSame([], $silent->notes);
    }

    public function testAQuizWhereAnyRightAnswerScoredIsNamed(): void
    {
        $question = static fn (string $player, string $answer, string $score): array => ['1', "L'agilité en entreprise, c'est", 'des intérimaires', 'moins de hiérarchie, plus de souplesse', 'se mouvoir avec légèreté', "s'adapter au macro-environnement", '-', '-', "moins de hiérarchie, plus de souplesse,s'adapter au macro-environnement", '20', $player, $answer, '' !== $score && '0' !== $score ? 'Correct' : 'Incorrect', '1', '0', $score];

        // One proposition picked, and points scored for it: the two right answers each counted.
        $report = KahootReportParser::parse([
            'Overview' => [['CEJM - Q 4.3 - Besoin de réactivité']],
            '1 Quiz' => [],
            'Raw Report Data' => [
                self::NEW_HEADER,
                $question('Pseudo A', 'moins de hiérarchie, plus de souplesse', '745'),
                $question('Pseudo B', "s'adapter au macro-environnement", '869'),
                $question('Pseudo C', 'des intérimaires', '0'),
            ],
        ], 'export.xlsx');

        $questions = $this->questions($report->document);
        self::assertSame(['qcm_multi', [2, 4]], [$questions[0]['type'], $questions[0]['correct']]);
        self::assertSame([[KahootNote::ANY_RIGHT_ANSWER, 1]], $this->notes($report));
        self::assertFalse($report->notes[0]->dropsQuestion());

        // A multiple-select question is played as one: nothing to say.
        $multiple = KahootReportParser::parse([
            'Overview' => [['CEJM - Q 4.3 - Besoin de réactivité']],
            '1 Multiple-select Quiz' => [],
            'Raw Report Data' => [self::NEW_HEADER, $question('Pseudo A', 'moins de hiérarchie, plus de souplesse', '745')],
        ], 'export.xlsx');

        self::assertSame([], $multiple->notes);

        // The older layout's « Quiz » with several right answers was a multiple choice: somebody picked
        // two, and one right pick alone scored only part of the points.
        $olderMultiple = KahootReportParser::parse([
            'Overview' => [['CEJM - Q 5.1 - Politiques de l\'emploi']],
            '1 Quiz' => [],
            'RawReportData Data' => [
                self::OLD_HEADER,
                $question('Pseudo A', "moins de hiérarchie, plus de souplesse, s'adapter au macro-environnement", '933'),
                $question('Pseudo B', "s'adapter au macro-environnement", '378'),
            ],
        ], 'export.xlsx');

        self::assertSame([], $olderMultiple->notes);
    }

    public function testTheRightAnswersAreFoundAmongPropositionsHoldingCommas(): void
    {
        $options = ['Oui, avec leur accord', 'Non', 'Oui, sans rien demander'];

        self::assertSame([1], KahootReportParser::correctPositions('Oui, avec leur accord', $options));
        self::assertSame([1, 2], KahootReportParser::correctPositions('Oui, avec leur accord, Non', $options));
        self::assertSame([2, 3], KahootReportParser::correctPositions('Non,Oui, sans rien demander', $options));
        self::assertNull(KahootReportParser::correctPositions('Peut-être', $options));
        self::assertNull(KahootReportParser::correctPositions('', $options));
    }

    public function testAnotherSpreadsheetIsNoReport(): void
    {
        $this->expectException(QuizCsvImportException::class);

        KahootReportParser::parse(['Feuil1' => [['Nom', 'Prénom']]], 'liste.xlsx');
    }

    public function testTwoExportsOfOneGameAreTheSameQuiz(): void
    {
        $sheets = [
            'Overview' => [['Révision Thème 4']],
            '1 Quiz' => [],
            'Raw Report Data' => [self::NEW_HEADER, ['1', 'Le SI est', 'un ensemble', 'un logiciel', '-', '-', '-', '-', 'un ensemble', '20', 'Pseudo A', 'un ensemble', 'Correct', '1', '0', '900']],
        ];

        self::assertSame(
            KahootReportParser::parse($sheets, 'CEJM - Révision Thème 4.xlsx')->fingerprint(),
            KahootReportParser::parse($sheets, 'CEJM - Révision Thème 4 (1).xlsx')->fingerprint(),
        );
    }

    /** @return list<array{0: string, 1: int}> */
    private function notes(KahootReport $report): array
    {
        return array_map(static fn (KahootNote $note): array => [$note->kind, $note->questionNumber], $report->notes);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array<string, mixed>>
     */
    private function questions(array $document): array
    {
        self::assertSame('moncampus-quiz/1', $document['format']);
        $questions = $document['questions'];
        self::assertIsArray($questions);

        return array_values(array_filter($questions, is_array(...)));
    }
}
