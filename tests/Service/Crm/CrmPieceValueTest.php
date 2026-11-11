<?php

declare(strict_types=1);

namespace App\Tests\Service\Crm;

use App\Entity\CrmPiece;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmPieceKind;
use App\Service\Crm\CrmPieceValue;
use App\Service\Crm\CrmRefusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What entering a piece accepts, by kind - the one reading of a value for the dialog, the
 * connector and the import.
 */
class CrmPieceValueTest extends TestCase
{
    private static function piece(CrmPieceKind $kind, array $choices = []): CrmPiece
    {
        $piece = new CrmPiece('p', 'Pièce', 'P', CrmPhase::Documents, $kind, [CrmModality::Initial], [CrmPath::Enrolment], 1);
        $piece->setChoices($choices);

        return $piece;
    }

    public function testABoxCarriesNoValue(): void
    {
        self::assertNull(CrmPieceValue::parse(self::piece(CrmPieceKind::Check), 'anything'));
    }

    public function testAChoiceMustBeOneOfItsValues(): void
    {
        $piece = self::piece(CrmPieceKind::Choice, ['Mail', 'Papier']);

        self::assertSame('Papier', CrmPieceValue::parse($piece, ' Papier '));
        $this->expectException(CrmRefusal::class);
        CrmPieceValue::parse($piece, 'Fax');
    }

    public function testAListAcceptsItsValuesWhateverTheCase(): void
    {
        self::assertSame('OPCO', CrmPieceValue::parse(self::piece(CrmPieceKind::List, ['Campus', 'OPCO']), 'opco'));
    }

    public function testATextIsRequiredAndTrimmed(): void
    {
        self::assertSame('Garage Lacroix', CrmPieceValue::parse(self::piece(CrmPieceKind::Text), '  Garage Lacroix '));
        $this->expectException(CrmRefusal::class);
        CrmPieceValue::parse(self::piece(CrmPieceKind::Text), '   ');
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function amounts(): iterable
    {
        yield 'integer' => [250, 250];
        yield 'zero is accepted' => [0, 0];
        yield 'numeric text' => ['6200', 6200];
        yield 'rounded' => [249.6, 250];
        yield 'euro sign and spaces' => ['1 250 €', 1250];
    }

    #[DataProvider('amounts')]
    public function testAnAmountIsAWholeNumberOfEuros(mixed $raw, int $expected): void
    {
        self::assertSame($expected, CrmPieceValue::parse(self::piece(CrmPieceKind::Amount), $raw));
    }

    public function testANegativeAmountIsRefused(): void
    {
        $this->expectException(CrmRefusal::class);
        CrmPieceValue::parse(self::piece(CrmPieceKind::Amount), -5);
    }

    public function testAPeriodEndsAfterItStarts(): void
    {
        self::assertSame(['start' => '2026-09-01', 'end' => '2028-08-31'], CrmPieceValue::parse(self::piece(CrmPieceKind::Period), ['start' => '2026-09-01', 'end' => '2028-08-31']));

        $this->expectException(CrmRefusal::class);
        CrmPieceValue::parse(self::piece(CrmPieceKind::Period), ['start' => '2026-09-01', 'end' => '2026-08-01']);
    }

    public function testAPeriodNeedsTwoRealDates(): void
    {
        $this->expectException(CrmRefusal::class);
        CrmPieceValue::parse(self::piece(CrmPieceKind::Period), ['start' => '2026-02-30', 'end' => '2027-01-01']);
    }

    public function testValuesAreWrittenTheWayTheTeamReadsThem(): void
    {
        self::assertSame('250 €', CrmPieceValue::format(self::piece(CrmPieceKind::Amount), 250));
        self::assertSame('01/09/2026 au 31/08/2028', CrmPieceValue::format(self::piece(CrmPieceKind::Period), ['start' => '2026-09-01', 'end' => '2028-08-31']));
        self::assertSame('Papier', CrmPieceValue::format(self::piece(CrmPieceKind::Choice, ['Papier']), 'Papier'));
        self::assertSame('', CrmPieceValue::format(self::piece(CrmPieceKind::Check), null));
    }

    public function testOnlyKnownPaymentModesAreAccepted(): void
    {
        self::assertSame('Virement', CrmPieceValue::paymentMode('virement'));
        self::assertNull(CrmPieceValue::paymentMode(''));
        $this->expectException(CrmRefusal::class);
        CrmPieceValue::paymentMode('Bitcoin');
    }
}
