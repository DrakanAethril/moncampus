<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\LaptopLoanDocumentBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule of the printed restitution form that is not just placing a value: which of its five
 * fixed tick boxes an administrable condition name lands in.
 *
 * The paper says "Comme neuf / Parfait état / Très bon état / État correct / Détérioré" and that is
 * all it will ever say; App\Entity\LaptopConditionType is whatever an administrator typed. So the
 * match is by name, tolerant of case, accents and spacing - and refuses everything else, because a
 * near-miss ticking an approximate box would put a condition on a signed document that nobody
 * recorded.
 */
class LaptopLoanDocumentBuilderTest extends TestCase
{
    /** @return iterable<string, array{?string, ?int}> */
    public static function conditionNames(): iterable
    {
        yield 'first box' => ['Comme neuf', 0];
        yield 'last box' => ['Détérioré', 4];
        yield 'middle box' => ['Très bon état', 2];

        // Same name typed differently - still that name.
        yield 'unaccented' => ['tres bon etat', 2];
        yield 'shouted' => ['ÉTAT CORRECT', 3];
        yield 'padded and double-spaced' => ["  Parfait   état\n", 1];

        // The référentiel this application ships with says none of the five.
        yield 'a condition the paper does not know' => ['Bon état', null];
        yield 'a prefix of one' => ['Comme', null];
        yield 'a superset of one' => ['Comme neuf ou presque', null];
        yield 'nothing recorded' => [null, null];
        yield 'blank' => ['', null];
    }

    #[DataProvider('conditionNames')]
    public function testTicksTheBoxNamedByTheCondition(?string $conditionName, ?int $expectedSlot): void
    {
        self::assertSame($expectedSlot, LaptopLoanDocumentBuilder::conditionSlot($conditionName));
    }
}
