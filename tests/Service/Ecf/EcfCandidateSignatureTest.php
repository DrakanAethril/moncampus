<?php

declare(strict_types=1);

namespace App\Tests\Service\Ecf;

use App\Entity\EcfBooklet;
use App\Entity\EcfVisa;
use App\Entity\User;
use App\Enum\EcfPart;
use App\Enum\EcfVisaSlot;
use App\Service\Ecf\EcfCandidateSignature;
use App\Service\Ecf\EcfMastery;
use App\Service\Ecf\EcfRefusal;
use App\Service\Ecf\EcfSigner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * The booklet's last line: offered once closed, signed by the student « pour information » with
 * their name as Nom Prénom, the remise date filled by that signature when nobody entered one - and
 * all of it withdrawn when the booklet is reopened.
 */
class EcfCandidateSignatureTest extends TestCase
{
    private EcfBooklet $booklet;
    private User $student;

    protected function setUp(): void
    {
        $this->student = (new User('candidate'))->setLastname('Lefèvre')->setFirstname('Hugo');
        $this->booklet = new EcfBooklet($this->student, 'TP-01281', '04');
    }

    private function service(): EcfCandidateSignature
    {
        return new EcfCandidateSignature($this->createStub(EntityManagerInterface::class));
    }

    private function refusalOf(callable $call): string
    {
        try {
            $call();
        } catch (EcfRefusal $refusal) {
            return $refusal->key;
        }
        self::fail('Expected a refusal.');
    }

    public function testOffersOnlyAClosedBookletOnce(): void
    {
        $staff = new User('staff');
        self::assertSame('ecfRefusalOfferNotClosedMessage', $this->refusalOf(fn () => $this->service()->offer($this->booklet, $staff, new \DateTimeImmutable())));

        $this->booklet->setClosedAt(new \DateTimeImmutable());
        $this->service()->offer($this->booklet, $staff, new \DateTimeImmutable());
        self::assertSame($staff, $this->booklet->getOfferedBy());
        self::assertSame('ecfRefusalAlreadyOfferedMessage', $this->refusalOf(fn () => $this->service()->offer($this->booklet, $staff, new \DateTimeImmutable())));
    }

    public function testTheStudentSignsAsNomPrenomAndTheRemiseDateFollows(): void
    {
        $this->booklet->setClosedAt(new \DateTimeImmutable());
        self::assertSame('ecfRefusalNotOfferedMessage', $this->refusalOf(fn () => $this->service()->sign($this->booklet, $this->student, new \DateTimeImmutable())));

        $this->service()->offer($this->booklet, new User('staff'), new \DateTimeImmutable());
        $this->service()->sign($this->booklet, $this->student, new \DateTimeImmutable('2026-10-04 14:30'));

        self::assertSame('Lefèvre Hugo', $this->booklet->getCandidateSignerName());
        self::assertSame('2026-10-04', $this->booklet->getRemittedOn()?->format('Y-m-d'));
        self::assertSame('ecfRefusalCandidateSignedMessage', $this->refusalOf(fn () => $this->service()->sign($this->booklet, $this->student, new \DateTimeImmutable())));
        self::assertSame('ecfRefusalCandidateSignedMessage', $this->refusalOf(fn () => $this->service()->withdraw($this->booklet)));
    }

    public function testAnEnteredRemiseDateIsKept(): void
    {
        $this->booklet->setClosedAt(new \DateTimeImmutable())->setRemittedOn(new \DateTimeImmutable('2026-09-30'));
        $this->service()->offer($this->booklet, new User('staff'), new \DateTimeImmutable());
        $this->service()->sign($this->booklet, $this->student, new \DateTimeImmutable('2026-10-04'));

        self::assertSame('2026-09-30', $this->booklet->getRemittedOn()?->format('Y-m-d'));
    }

    public function testReopeningTheBookletWithdrawsTheOfferAndTheSignature(): void
    {
        $this->booklet->setClosedAt(new \DateTimeImmutable());
        new EcfVisa($this->booklet, null, EcfPart::Synthesis, EcfVisaSlot::Representative, new User('director'), 'Delmas', new \DateTimeImmutable(), new \DateTimeImmutable());
        $this->service()->offer($this->booklet, new User('staff'), new \DateTimeImmutable());
        $this->service()->sign($this->booklet, $this->student, new \DateTimeImmutable());

        (new EcfSigner($this->createStub(EntityManagerInterface::class), new EcfMastery()))->unsign($this->booklet, null, EcfPart::Synthesis);

        self::assertFalse($this->booklet->isClosed());
        self::assertFalse($this->booklet->isOffered());
        self::assertFalse($this->booklet->isCandidateSigned());
    }
}
