<?php

declare(strict_types=1);

namespace App\Service\Ecf;

use App\Entity\EcfBooklet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The booklet's last line, « Un exemplaire du livret a été remis au candidat pour information … /
 * Signature du candidat pour information » (design/validated/ecf-booklet.md §12).
 *
 *  - The administration offers the booklet to the student once it is closed - the representative's
 *    visa is what makes it the final copy - and may withdraw the offer until the student signs.
 *  - The student signs their own booklet, offered and still closed; the print reads « Signé le … par
 *    {Nom Prénom} », the name copied at the click. The date of remise, when nobody entered one,
 *    becomes the day of that signature: it is the « contre signature le » of the same line.
 *  - Reopening the booklet (EcfSigner::unsign() on the synthesis) withdraws both.
 */
class EcfCandidateSignature
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function offer(EcfBooklet $booklet, User $by, \DateTimeImmutable $now): void
    {
        if (!$booklet->isClosed()) {
            throw new EcfRefusal('ecfRefusalOfferNotClosedMessage');
        }
        if ($booklet->isOffered()) {
            throw new EcfRefusal('ecfRefusalAlreadyOfferedMessage');
        }

        $booklet->offer($by, $now);
        $this->entityManager->flush();
    }

    public function withdraw(EcfBooklet $booklet): void
    {
        if ($booklet->isCandidateSigned()) {
            throw new EcfRefusal('ecfRefusalCandidateSignedMessage');
        }
        if (!$booklet->isOffered()) {
            throw new EcfRefusal('ecfRefusalNotOfferedMessage');
        }

        $booklet->withdrawOffer();
        $this->entityManager->flush();
    }

    public function sign(EcfBooklet $booklet, User $student, \DateTimeImmutable $now): void
    {
        if ($booklet->getStudent() !== $student) {
            throw new \LogicException('Only the booklet\'s own student signs it.');
        }
        if (!$booklet->isOffered() || !$booklet->isClosed()) {
            throw new EcfRefusal('ecfRefusalNotOfferedMessage');
        }
        if ($booklet->isCandidateSigned()) {
            throw new EcfRefusal('ecfRefusalCandidateSignedMessage');
        }

        $booklet->signAsCandidate(self::printedName($student), $now);
        if (null === $booklet->getRemittedOn()) {
            $booklet->setRemittedOn($now->setTime(0, 0));
        }
        $this->entityManager->flush();
    }

    // « par Nom Prénom », in that order, as asked.
    public static function printedName(User $student): string
    {
        $name = trim(trim((string) $student->getLastname()).' '.trim((string) $student->getFirstname()));

        return '' !== $name ? $name : ($student->getDisplayName() ?? $student->getUsername());
    }
}
