<?php

declare(strict_types=1);

namespace App\SeriousGame\Template;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * The deterministic draw of one question (design/validated/serious-games.md §5.1): the same seed
 * and the same unit always give the same sequence, which is what makes a question replayable from
 * its seed and its template version alone.
 *
 * Xoshiro256** is specified to the bit, so the sequence does not depend on the machine. Every draw
 * goes through float() - int(), pick() and shuffle() are written on it the way the reference's
 * helpers are written on `r()`.
 */
final class SeededRandom
{
    private Randomizer $randomizer;

    public function __construct(int $seed, string $salt = '')
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed.'|'.$salt, true)));
    }

    /** A float in [0 ; 1[. */
    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    /** An integer of [a ; b], both included. */
    public function int(int $a, int $b): int
    {
        if ($b < $a) {
            [$a, $b] = [$b, $a];
        }

        return $a + (int) floor($this->float() * ($b - $a + 1));
    }

    /**
     * @template T
     *
     * @param array<array-key, T> $items
     *
     * @return T
     */
    public function pick(array $items): mixed
    {
        $items = array_values($items);
        if ([] === $items) {
            throw new \InvalidArgumentException('pick() sur une liste vide');
        }

        return $items[(int) floor($this->float() * \count($items))];
    }

    /**
     * @template T
     *
     * @param array<array-key, T> $items
     *
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        $a = array_values($items);
        for ($k = \count($a) - 1; $k > 0; --$k) {
            $j = (int) floor($this->float() * ($k + 1));
            [$a[$k], $a[$j]] = [$a[$j], $a[$k]];
        }

        return $a;
    }
}
