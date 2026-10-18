<?php

declare(strict_types=1);

namespace App\SeriousGame\Biology;

/**
 * The standard genetic code (NCBI translation table 1) and the operations on a strand Station
 * Abysse asks: complementary strand, transcription, translation. Pure functions over strings of
 * the letters A, C, G, T (DNA) or A, C, G, U (RNA).
 *
 * Amino acids are written with their three-letter abbreviation (« Met », « Leu »), the stop codons
 * « Stop » - as the SVT programme's code tables do.
 */
final class GeneticCode
{
    /** The 64 codons in the order U, C, A, G for each of the three letters, one amino acid each. */
    private const string TABLE = 'FFLLSSSSYY**CC*WLLLLPPPPHHQQRRRRIIIMTTTTNNKKSSRRVVVVAAAADDEEGGGG';
    private const string BASES = 'UCAG';
    private const array THREE_LETTERS = ['F' => 'Phe', 'L' => 'Leu', 'S' => 'Ser', 'Y' => 'Tyr', '*' => 'Stop', 'C' => 'Cys', 'W' => 'Trp', 'P' => 'Pro', 'H' => 'His', 'Q' => 'Gln', 'R' => 'Arg', 'I' => 'Ile', 'M' => 'Met', 'T' => 'Thr', 'N' => 'Asn', 'K' => 'Lys', 'V' => 'Val', 'A' => 'Ala', 'D' => 'Asp', 'E' => 'Glu', 'G' => 'Gly'];
    public const string STOP = 'Stop';

    /** The amino acid of an RNA codon (a DNA codon is read with T for U). */
    public static function aminoAcid(string $codon): string
    {
        $codon = strtoupper(str_replace('T', 'U', $codon));
        if (!preg_match('/^[UCAG]{3}$/', $codon)) {
            throw new \InvalidArgumentException('un codon est fait de trois bases, « '.$codon.' » n’en est pas un');
        }
        $index = 16 * strpos(self::BASES, $codon[0]) + 4 * strpos(self::BASES, $codon[1]) + strpos(self::BASES, $codon[2]);

        return self::THREE_LETTERS[self::TABLE[$index]];
    }

    /** The complementary DNA strand: A with T, C with G. */
    public static function complement(string $dna): string
    {
        return strtr(strtoupper($dna), 'ATCG', 'TAGC');
    }

    /** The messenger RNA transcribed from a template strand: A → U, T → A, C → G, G → C. */
    public static function transcribe(string $template): string
    {
        return strtr(strtoupper($template), 'ATCG', 'UAGC');
    }

    /**
     * The amino acids an RNA codes for, read codon by codon from its first letter, up to the first
     * stop codon (not included).
     *
     * @return list<string>
     */
    public static function translate(string $rna): array
    {
        $out = [];
        for ($i = 0; $i + 3 <= \strlen($rna); $i += 3) {
            $amino = self::aminoAcid(substr($rna, $i, 3));
            if (self::STOP === $amino) {
                break;
            }
            $out[] = $amino;
        }

        return $out;
    }

    /** A strand written in groups of three, as the station's sequencer prints it. */
    public static function groups(string $sequence): string
    {
        return trim((string) preg_replace('/(.{3})/', '$1 ', $sequence));
    }

    /** Whether an RNA codon is a stop codon. */
    public static function isStop(string $codon): bool
    {
        return self::STOP === self::aminoAcid($codon);
    }
}
