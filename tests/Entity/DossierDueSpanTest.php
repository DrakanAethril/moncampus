<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Enum\DossierDepositType;
use PHPUnit\Framework\TestCase;

/**
 * « Période », as the cible reads it: the first and last date limite among the dossier's documents.
 *
 * It is read from the pieces rather than from the dossier's own startsOn/endsOn, which are a label
 * somebody typed at the wizard - so the three cases that matter are the documents carrying no date
 * at all, carrying one, and carrying several out of order.
 */
class DossierDueSpanTest extends TestCase
{
    public function testSpanIsEmptyWhenNoDocumentCarriesADate(): void
    {
        $dossier = new Dossier();
        $this->document($dossier, null);

        self::assertSame(['start' => null, 'end' => null], $dossier->documentDueSpan());
    }

    public function testSpanRunsFromTheEarliestToTheLatestDueDate(): void
    {
        $dossier = new Dossier();
        $this->document($dossier, '2026-10-15');
        $this->document($dossier, '2026-09-13');
        $this->document($dossier, '2026-10-20');

        $span = $dossier->documentDueSpan();

        self::assertSame('2026-09-13', $span['start']?->format('Y-m-d'));
        self::assertSame('2026-10-20', $span['end']?->format('Y-m-d'));
    }

    /** A document with no date limite is not part of the span - it is not a date of its own. */
    public function testUndatedDocumentsAreLeftOutOfTheSpan(): void
    {
        $dossier = new Dossier();
        $this->document($dossier, '2026-10-15');
        $this->document($dossier, null);

        $span = $dossier->documentDueSpan();

        self::assertSame('2026-10-15', $span['start']?->format('Y-m-d'));
        self::assertSame('2026-10-15', $span['end']?->format('Y-m-d'));
    }

    private function document(Dossier $dossier, ?string $dueOn): DossierDocument
    {
        $document = (new DossierDocument($dossier))
            ->setName('Rapport de stage')
            ->setDepositType(DossierDepositType::Upload)
            ->setDueOn(null === $dueOn ? null : new \DateTimeImmutable($dueOn));

        $dossier->addDocument($document);

        return $document;
    }
}
