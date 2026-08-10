<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HelpSearch;
use PHPUnit\Framework\TestCase;

/**
 * The help's own search, which reads a few hundred rows in PHP rather than through the database:
 * the whole index fits in one query, and a MySQL fulltext index would still not answer the two
 * things this screen needs - accent-insensitive matching on French content, and an excerpt with
 * the searched term marked inside it (design_handoff_aide, écran 3).
 *
 * Everything below is pure string work, which is why it is unit-tested rather than clicked.
 */
class HelpSearchTest extends TestCase
{
    /** @return list<array{key: string, title: string, text: string}> */
    private function rows(): array
    {
        return [
            ['key' => 'audio-create', 'title' => 'Créer un enregistrement audio', 'text' => 'Enregistrez au micro un fichier commun à toute la classe.'],
            ['key' => 'audio-follow', 'title' => 'Suivre les écoutes', 'text' => "Les statistiques d'écoute indiquent qui a écouté l'enregistrement audio en entier."],
            ['key' => 'gradebook', 'title' => 'Saisir les notes', 'text' => 'La saisie rapide remplit une colonne du carnet de notes.'],
        ];
    }

    public function testFindsRowsWhoseTitleOrTextHoldsTheTerm(): void
    {
        $search = new HelpSearch();

        $keys = array_column($search->search('audio', $this->rows()), 'key');

        self::assertSame(['audio-create', 'audio-follow'], $keys);
    }

    public function testTitleMatchesOutrankTextMatches(): void
    {
        $search = new HelpSearch();

        // "enregistrement" is in the second row's title and in the third row's text - and the rows
        // are handed over in the other order, so only the score can explain the result.
        $rows = array_reverse($this->rows());
        $keys = array_column($search->search('enregistrement', $rows), 'key');

        self::assertSame(['audio-create', 'audio-follow'], $keys);
    }

    public function testIgnoresAccentsAndCase(): void
    {
        $search = new HelpSearch();

        self::assertSame(['audio-follow'], array_column($search->search('ECOUTE', $this->rows()), 'key'));
        self::assertSame(['audio-create'], array_column($search->search('créer', $this->rows()), 'key'));
    }

    public function testEveryTermMustMatchSomewhereInTheRow(): void
    {
        $search = new HelpSearch();

        self::assertSame(['audio-create'], array_column($search->search('audio micro', $this->rows()), 'key'));
        self::assertSame([], $search->search('audio bulletin', $this->rows()));
    }

    public function testAnEmptyQueryMatchesNothing(): void
    {
        $search = new HelpSearch();

        self::assertSame([], $search->search('   ', $this->rows()));
    }

    public function testTheExcerptMarksTheTermAndKeepsTheOriginalSpelling(): void
    {
        $search = new HelpSearch();

        $hit = $search->search('ecoute', $this->rows())[0];
        $marked = array_values(array_filter($hit['excerpt'], static fn (array $segment): bool => $segment['match']));

        // Accents are ignored to *find* the term, never to display it back - and a term that lands
        // on a prefix marks the whole word, so "ecoute" lights up "écouté" as well.
        self::assertSame(['écoute', 'écouté'], array_column($marked, 'text'));
        self::assertSame(
            "Les statistiques d'écoute indiquent qui a écouté l'enregistrement audio en entier.",
            implode('', array_column($hit['excerpt'], 'text')),
        );
    }

    public function testTheExcerptOpensOnTheFirstMatchWhenTheTextIsLong(): void
    {
        $search = new HelpSearch();
        $long = str_repeat('Un paragraphe sans rapport. ', 20).'Le tirage au sort désigne un étudiant.';

        $hit = $search->search('tirage', [['key' => 'k', 'title' => 'Titre', 'text' => $long]])[0];
        $excerpt = implode('', array_column($hit['excerpt'], 'text'));

        self::assertStringContainsString('tirage au sort', $excerpt);
        self::assertStringStartsWith('…', $excerpt);
        self::assertLessThan(mb_strlen($long), mb_strlen($excerpt));
    }

    public function testSegmentsMarkEveryOccurrenceInAnyText(): void
    {
        $search = new HelpSearch();

        $segments = $search->segments('Créer un travail', 'travail');

        self::assertSame([
            ['text' => 'Créer un ', 'match' => false],
            ['text' => 'travail', 'match' => true],
        ], $segments);
    }
}
