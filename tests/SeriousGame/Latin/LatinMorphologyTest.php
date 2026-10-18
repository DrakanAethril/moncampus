<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Latin;

use App\SeriousGame\Latin\LatinMorphology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The declensions Les Tablettes de Pompéi asks for, checked against the grammar's tables - a form
 * the game expects is a form a Latin teacher would write on the board.
 */
class LatinMorphologyTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string, list<string>}> */
    public static function paradigms(): iterable
    {
        yield 'rosa singulier' => [['rosa', 'rosae', 'f'], 'sg', ['rosa', 'rosa', 'rosam', 'rosae', 'rosae', 'rosa']];
        yield 'rosa pluriel' => [['rosa', 'rosae', 'f'], 'pl', ['rosae', 'rosae', 'rosas', 'rosarum', 'rosis', 'rosis']];
        yield 'nauta masculin' => [['nauta', 'nautae', 'm'], 'sg', ['nauta', 'nauta', 'nautam', 'nautae', 'nautae', 'nauta']];
        yield 'dominus singulier' => [['dominus', 'domini', 'm'], 'sg', ['dominus', 'domine', 'dominum', 'domini', 'domino', 'domino']];
        yield 'dominus pluriel' => [['dominus', 'domini', 'm'], 'pl', ['domini', 'domini', 'dominos', 'dominorum', 'dominis', 'dominis']];
        yield 'filius vocatif' => [['filius', 'filii', 'm'], 'sg', ['filius', 'fili', 'filium', 'filii', 'filio', 'filio']];
        yield 'puer' => [['puer', 'pueri', 'm'], 'sg', ['puer', 'puer', 'puerum', 'pueri', 'puero', 'puero']];
        yield 'ager' => [['ager', 'agri', 'm'], 'pl', ['agri', 'agri', 'agros', 'agrorum', 'agris', 'agris']];
        yield 'templum singulier' => [['templum', 'templi', 'n'], 'sg', ['templum', 'templum', 'templum', 'templi', 'templo', 'templo']];
        yield 'templum pluriel' => [['templum', 'templi', 'n'], 'pl', ['templa', 'templa', 'templa', 'templorum', 'templis', 'templis']];
        yield 'rex singulier' => [['rex', 'regis', 'm'], 'sg', ['rex', 'rex', 'regem', 'regis', 'regi', 'rege']];
        yield 'rex pluriel' => [['rex', 'regis', 'm'], 'pl', ['reges', 'reges', 'reges', 'regum', 'regibus', 'regibus']];
        yield 'corpus singulier' => [['corpus', 'corporis', 'n'], 'sg', ['corpus', 'corpus', 'corpus', 'corporis', 'corpori', 'corpore']];
        yield 'corpus pluriel' => [['corpus', 'corporis', 'n'], 'pl', ['corpora', 'corpora', 'corpora', 'corporum', 'corporibus', 'corporibus']];
        yield 'civis pluriel' => [['civis', 'civis', 'm'], 'pl', ['cives', 'cives', 'cives', 'civium', 'civibus', 'civibus']];
        yield 'urbs pluriel' => [['urbs', 'urbis', 'f'], 'pl', ['urbes', 'urbes', 'urbes', 'urbium', 'urbibus', 'urbibus']];
        yield 'nox pluriel' => [['nox', 'noctis', 'f'], 'pl', ['noctes', 'noctes', 'noctes', 'noctium', 'noctibus', 'noctibus']];
        yield 'canis exception' => [['canis', 'canis', 'm', '', 'consonne'], 'pl', ['canes', 'canes', 'canes', 'canum', 'canibus', 'canibus']];
        yield 'mare singulier' => [['mare', 'maris', 'n'], 'sg', ['mare', 'mare', 'mare', 'maris', 'mari', 'mari']];
        yield 'mare pluriel' => [['mare', 'maris', 'n'], 'pl', ['maria', 'maria', 'maria', 'marium', 'maribus', 'maribus']];
        yield 'animal' => [['animal', 'animalis', 'n'], 'pl', ['animalia', 'animalia', 'animalia', 'animalium', 'animalibus', 'animalibus']];
        yield 'manus singulier' => [['manus', 'manus', 'f'], 'sg', ['manus', 'manus', 'manum', 'manus', 'manui', 'manu']];
        yield 'manus pluriel' => [['manus', 'manus', 'f'], 'pl', ['manus', 'manus', 'manus', 'manuum', 'manibus', 'manibus']];
        yield 'cornu' => [['cornu', 'cornus', 'n'], 'pl', ['cornua', 'cornua', 'cornua', 'cornuum', 'cornibus', 'cornibus']];
        yield 'res singulier' => [['res', 'rei', 'f'], 'sg', ['res', 'res', 'rem', 'rei', 'rei', 're']];
        yield 'dies pluriel' => [['dies', 'diei', 'm'], 'pl', ['dies', 'dies', 'dies', 'dierum', 'diebus', 'diebus']];
    }

    /**
     * @param list<string> $entry
     * @param list<string> $expected
     */
    #[DataProvider('paradigms')]
    public function testANounIsDeclinedAsTheGrammarDoes(array $entry, string $number, array $expected): void
    {
        self::assertSame($expected, LatinMorphology::paradigm($entry, $number));
    }

    public function testTheDeclensionIsReadFromTheGenitive(): void
    {
        self::assertSame(1, LatinMorphology::declension(['nauta', 'nautae', 'm']));
        self::assertSame(2, LatinMorphology::declension(['puer', 'pueri', 'm']));
        self::assertSame(3, LatinMorphology::declension(['corpus', 'corporis', 'n']));
        self::assertSame(4, LatinMorphology::declension(['manus', 'manus', 'f']));
        self::assertSame(5, LatinMorphology::declension(['res', 'rei', 'f']));
    }

    public function testAnIStemAcceptsTheOldAccusativePlural(): void
    {
        self::assertSame(['cives', 'civis'], LatinMorphology::forms(['civis', 'civis', 'm'], 'acc.pl'));
        self::assertSame(['reges'], LatinMorphology::forms(['rex', 'regis', 'm'], 'acc.pl'));
    }

    public function testAnAmbiguousFormHasEveryAnalysis(): void
    {
        self::assertSame(['gen.sg', 'dat.sg', 'nom.pl', 'voc.pl'], LatinMorphology::analyses(['rosa', 'rosae', 'f'], 'rosae'));
        self::assertSame(['dat.pl', 'abl.pl'], LatinMorphology::analyses(['rosa', 'rosae', 'f'], 'rosīs'));
        self::assertSame(['nom.sg', 'voc.sg', 'nom.pl', 'voc.pl', 'acc.pl'], LatinMorphology::analyses(['res', 'rei', 'f'], 'res'));
    }

    public function testAnotherDeclensionsEndingsOnTheStem(): void
    {
        self::assertSame('rosum', LatinMorphology::formAs(['rosa', 'rosae', 'f'], 2, 'acc.sg'));
        self::assertSame('domina', LatinMorphology::formAs(['dominus', 'domini', 'm'], 1, 'abl.sg'));
        self::assertSame('corpi', LatinMorphology::formAs(['corpus', 'corporis', 'n'], 2, 'gen.sg', 'nominative'));
        self::assertSame('regum', LatinMorphology::formAs(['rex', 'regis', 'm'], 2, 'acc.sg'));
    }

    /** @return iterable<string, array{list<string>, string, string, list<string>}> */
    public static function adjectives(): iterable
    {
        yield 'bonus masculin' => [['bonus', 'bona', 'bonum', 'boni'], 'm', 'sg', ['bonus', 'bone', 'bonum', 'boni', 'bono', 'bono']];
        yield 'bona féminin pluriel' => [['bonus', 'bona', 'bonum', 'boni'], 'f', 'pl', ['bonae', 'bonae', 'bonas', 'bonarum', 'bonis', 'bonis']];
        yield 'bonum neutre pluriel' => [['bonus', 'bona', 'bonum', 'boni'], 'n', 'pl', ['bona', 'bona', 'bona', 'bonorum', 'bonis', 'bonis']];
        yield 'pulcher' => [['pulcher', 'pulchra', 'pulchrum', 'pulchri'], 'f', 'sg', ['pulchra', 'pulchra', 'pulchram', 'pulchrae', 'pulchrae', 'pulchra']];
        yield 'fortis masculin' => [['fortis', 'fortis', 'forte', 'fortis'], 'm', 'sg', ['fortis', 'fortis', 'fortem', 'fortis', 'forti', 'forti']];
        yield 'forte neutre pluriel' => [['fortis', 'fortis', 'forte', 'fortis'], 'n', 'pl', ['fortia', 'fortia', 'fortia', 'fortium', 'fortibus', 'fortibus']];
        yield 'acer féminin' => [['acer', 'acris', 'acre', 'acris'], 'f', 'sg', ['acris', 'acris', 'acrem', 'acris', 'acri', 'acri']];
        yield 'ingens neutre' => [['ingens', 'ingens', 'ingens', 'ingentis'], 'n', 'sg', ['ingens', 'ingens', 'ingens', 'ingentis', 'ingenti', 'ingenti']];
    }

    /**
     * @param list<string> $entry
     * @param list<string> $expected
     */
    #[DataProvider('adjectives')]
    public function testAnAdjectiveIsDeclinedInEachGender(array $entry, string $gender, string $number, array $expected): void
    {
        self::assertSame($expected, LatinMorphology::adjectiveParadigm($entry, $gender, $number));
    }

    public function testThePronouns(): void
    {
        self::assertSame(['hic', '—', 'hunc', 'huius', 'huic', 'hoc'], LatinMorphology::pronounParadigm('hic', 'm', 'sg'));
        self::assertSame(['haec', '—', 'haec', 'horum', 'his', 'his'], LatinMorphology::pronounParadigm('hic', 'n', 'pl'));
        self::assertSame(['ea', '—', 'eam', 'eius', 'ei', 'ea'], LatinMorphology::pronounParadigm('is', 'f', 'sg'));
        self::assertSame(['illud', '—', 'illud', 'illius', 'illi', 'illo'], LatinMorphology::pronounParadigm('ille', 'n', 'sg'));
        self::assertSame(['qui', '—', 'quos', 'quorum', 'quibus', 'quibus'], LatinMorphology::pronounParadigm('qui', 'm', 'pl'));
        self::assertSame(['nos', '—', 'nos', 'nostri', 'nobis', 'nobis'], LatinMorphology::pronounParadigm('ego', 'm', 'pl'));
        self::assertSame(['ei', 'ii'], LatinMorphology::pronounForms('is', 'm', 'nom.pl'));
        self::assertSame(['acc.sg', 'abl.sg', 'acc.pl', 'abl.pl'], LatinMorphology::pronounAnalyses('se', 'se'));
        self::assertSame(['nom.sg.n', 'acc.sg.n', 'abl.sg.m', 'abl.sg.n'], LatinMorphology::pronounAnalyses('hic', 'hoc'));
    }

    public function testTheComparisonIgnoresMacronsCaseAndTheLettersJAndV(): void
    {
        self::assertTrue(LatinMorphology::same('rosā', 'rosa'));
        self::assertTrue(LatinMorphology::same('  Dominī ', 'domini'));
        self::assertTrue(LatinMorphology::same('cuius', 'cujus'));
        self::assertTrue(LatinMorphology::same('uos', 'vos'));
        self::assertFalse(LatinMorphology::same('rosam', 'rosa'));
    }

    public function testTheLabels(): void
    {
        self::assertSame('accusatif pluriel', LatinMorphology::caseLabel('acc.pl'));
        self::assertSame('génitif singulier féminin', LatinMorphology::caseLabel('gen.sg.f'));
        self::assertSame('3e déclinaison', LatinMorphology::declensionName(3));
        self::assertSame('1re déclinaison', LatinMorphology::declensionName(1));
    }
}
