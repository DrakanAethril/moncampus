<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Latin;

use App\SeriousGame\Latin\LatinMorphology;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type Noun array{entry: list<string>, sg: list<string>, pl: list<string>}
 * @phpstan-type Adjective array{entry: list<string>, sg: array<string, list<string>>, pl: array<string, list<string>>}
 * @phpstan-type Pronoun array{lemma: string, personal?: bool, sg: array<string, list<string>>|list<string>, pl: array<string, list<string>>|list<string>}
 * @phpstan-type Library array{tables: array{nouns: list<Noun>, adjectives: list<Adjective>, pronouns: list<Pronoun>}, glossary: array{villa: list<array{0: string, 1: string}>, grammaire: list<array{0: string, 1: string}>}}
 *
 * The Bibliothèque of Les Tablettes de Pompéi (public/serious-games/pompei/library.js) is a dump of
 * the grammar that corrects the answers: every form a student reads there is the one the server
 * expects. A change to LatinMorphology that is not dumped again fails here.
 */
class PompeiLibraryTest extends TestCase
{
    /** @return Library */
    private static function library(): array
    {
        $script = (string) file_get_contents(__DIR__.'/../../../public/serious-games/pompei/library.js');
        self::assertSame(1, preg_match('/root\.POMLibrary = (\{.*\});\s*\}\)\(/s', $script, $m));
        $data = json_decode($m[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /* @var Library $data */
        return $data;
    }

    public function testTheNounTablesAreTheGrammars(): void
    {
        foreach (self::library()['tables']['nouns'] as $noun) {
            foreach (['sg', 'pl'] as $number) {
                $expected = array_map(static fn (string $case): string => implode(' / ', LatinMorphology::forms($noun['entry'], $case.'.'.$number)), LatinMorphology::CASES);
                self::assertSame($expected, $noun[$number], $noun['entry'][0].' '.$number);
            }
        }
    }

    public function testTheAdjectiveAndPronounTablesAreTheGrammars(): void
    {
        $tables = self::library()['tables'];
        foreach ($tables['adjectives'] as $adjective) {
            foreach (['sg', 'pl'] as $number) {
                foreach (LatinMorphology::GENDERS as $gender) {
                    $expected = array_map(static fn (string $case): string => implode(' / ', LatinMorphology::adjectiveForms($adjective['entry'], $case.'.'.$number.'.'.$gender)), LatinMorphology::CASES);
                    self::assertSame($expected, $adjective[$number][$gender], $adjective['entry'][0].' '.$number.' '.$gender);
                }
            }
        }
        foreach ($tables['pronouns'] as $pronoun) {
            foreach (['sg', 'pl'] as $number) {
                if (true === ($pronoun['personal'] ?? false)) {
                    $expected = array_map(static fn (string $case): string => implode(' / ', LatinMorphology::pronounForms($pronoun['lemma'], 'm', $case.'.'.$number)) ?: '—', LatinMorphology::CASES);
                    self::assertSame($expected, $pronoun[$number], $pronoun['lemma'].' '.$number);
                    continue;
                }
                foreach (LatinMorphology::GENDERS as $gender) {
                    $expected = array_map(static fn (string $case): string => implode(' / ', LatinMorphology::pronounForms($pronoun['lemma'], $gender, $case.'.'.$number)), LatinMorphology::CASES_WITHOUT_VOCATIVE);
                    self::assertSame($expected, $pronoun[$number][$gender], $pronoun['lemma'].' '.$number.' '.$gender);
                }
            }
        }
    }

    public function testTheGlossaryExplainsEveryLatinWordTheVillaUses(): void
    {
        $terms = array_map(static fn (array $entry): string => $entry[0], self::library()['glossary']['villa']);
        foreach (['atrium', 'tablinum', 'triclinium', 'peristylium', 'thermae', 'hortus', 'lararium', 'stilus', 'groma', 'Cave canem', 'fauces'] as $word) {
            self::assertContains($word, $terms);
        }
    }
}
