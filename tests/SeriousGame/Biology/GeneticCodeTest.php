<?php

declare(strict_types=1);

namespace App\Tests\SeriousGame\Biology;

use App\SeriousGame\Biology\GeneticCode;
use PHPUnit\Framework\TestCase;

/**
 * Station Abysse's genetic code: the standard table (NCBI table 1), complementary strand,
 * transcription and translation as the SVT programme states them.
 */
class GeneticCodeTest extends TestCase
{
    public function testTheStandardTable(): void
    {
        self::assertSame('Met', GeneticCode::aminoAcid('AUG'));
        self::assertSame('Phe', GeneticCode::aminoAcid('UUU'));
        self::assertSame('Trp', GeneticCode::aminoAcid('UGG'));
        self::assertSame('Gly', GeneticCode::aminoAcid('GGG'));
        self::assertSame('Leu', GeneticCode::aminoAcid('CUA'));
        foreach (['UAA', 'UAG', 'UGA'] as $stop) {
            self::assertTrue(GeneticCode::isStop($stop), $stop);
        }
        self::assertSame('Met', GeneticCode::aminoAcid('ATG'), 'a DNA codon reads with T for U');
    }

    public function testComplementTranscriptionTranslation(): void
    {
        self::assertSame('TACGGT', GeneticCode::complement('ATGCCA'));
        self::assertSame('UACGGU', GeneticCode::transcribe('ATGCCA'));
        self::assertSame(['Met', 'Gly', 'Ser'], GeneticCode::translate('AUGGGAUCUUAAGCC'), 'the stop codon ends the protein');
        self::assertSame('ATG CCA TT', GeneticCode::groups('ATGCCATT'));
    }
}
