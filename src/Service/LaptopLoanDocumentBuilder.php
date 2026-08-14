<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LaptopLoan;
use App\Entity\User;
use App\Enum\LaptopLoanType;
use App\Repository\InternshipFormationCenterRepository;
use App\Repository\ProgramRepository;

/**
 * Turns one LaptopLoan into the flat set of already-formatted values the printed conventions
 * overlay on their scanned background - see templates/laptop/documents/.
 *
 * Everything here is a string the template only has to place, dates and money included: the
 * templates carry millimetre coordinates and nothing else, so adjusting the layout never means
 * touching formatting rules and the other way round.
 *
 * Nothing is frozen at signing time. The programme and its dates are re-read at every print, so a
 * convention reprinted after the borrower changed programme no longer says what the signed copy
 * said. Accepted on 2026-08-14, along with blank values being filled in by hand: any active account
 * may borrow (App\Service\LaptopLoanEligibility::isEligibleBorrower() only checks the account is
 * active), including a teacher with no programme at all.
 *
 * @phpstan-type LoanDocumentData array{
 *     ufaName: string,
 *     establishmentName: string,
 *     directorName: string,
 *     representativeLastName: string,
 *     representativeFirstName: string,
 *     representativeRole: string,
 *     borrowerLastName: string,
 *     borrowerFirstName: string,
 *     programName: string,
 *     programStartDate: string,
 *     programEndDate: string,
 *     assetTag: string,
 *     brand: string,
 *     model: string,
 *     deviceLabel: string,
 *     serialNumber: string,
 *     conditionName: string,
 *     accessories: string,
 *     replacementValue: string,
 *     lentAt: string,
 *     dueAt: string,
 *     city: string,
 *     returnedAt: string,
 *     returnNotes: string,
 *     returnConditionSlot: int|null,
 *     returnAccessoryConditionSlot: int|null,
 * }
 */
class LaptopLoanDocumentBuilder
{
    /**
     * The five tick boxes printed on the restitution form, in the order they appear on the paper.
     * They are fixed; App\Entity\LaptopConditionType is freely administrable, so a loan's condition
     * finds its box by name or finds none at all - see conditionSlot().
     */
    private const array CONDITION_SLOTS = [
        'comme neuf',
        'parfait etat',
        'tres bon etat',
        'etat correct',
        'deteriore',
    ];

    // The representative's role is the same on every convention this institution prints, and there
    // is nowhere to configure it - InternshipFormationCenter names the director, not their title.
    private const string REPRESENTATIVE_ROLE = 'Directeur';

    // The CFC is a different legal entity from the UFA and has no settings screen of its own:
    // InternshipFormationCenter holds the *UFA*'s name, which is not what a CFC convention must
    // say. Until there is somewhere to configure it, the name comes from the paper model itself.
    private const string CFC_ESTABLISHMENT_NAME = 'Centre de Formation Continue de l’Institution BEAUPEYRAT';

    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly InternshipFormationCenterRepository $formationCenterRepository,
    ) {
    }

    /** @return LoanDocumentData */
    public function build(LaptopLoan $loan): array
    {
        $formationCenter = $this->formationCenterRepository->findSingleton();
        $laptop = $loan->getLaptop();
        $borrower = $loan->getBorrower();
        $program = $borrower instanceof User ? $this->programRepository->findActiveForStudent($borrower) : null;

        $directorFirstName = $formationCenter?->getDirectorFirstName() ?? '';
        $directorLastName = $formationCenter?->getDirectorLastName() ?? '';

        return [
            'ufaName' => $formationCenter?->getCompanyName() ?? '',
            // The CFC model spells the institution out in its opening sentence, where the UFA one
            // has a "NOM UFA" field: two documents, two legal entities, one configured field.
            'establishmentName' => LaptopLoanType::Cfc === $loan->getLoanType()
                ? self::CFC_ESTABLISHMENT_NAME
                : ($formationCenter?->getCompanyName() ?? ''),
            'directorName' => trim($directorFirstName.' '.$directorLastName),
            'representativeLastName' => $directorLastName,
            'representativeFirstName' => $directorFirstName,
            'representativeRole' => self::REPRESENTATIVE_ROLE,
            'borrowerLastName' => $borrower?->getLastname() ?? '',
            'borrowerFirstName' => $borrower?->getFirstname() ?? '',
            'programName' => $program?->getDisplayName() ?? '',
            'programStartDate' => self::date($program?->getStartDate()),
            'programEndDate' => self::date($program?->getEndDate()),
            'assetTag' => $laptop?->getAssetTag() ?? '',
            'brand' => $laptop?->getBrand() ?? '',
            'model' => $laptop?->getModel() ?? '',
            // The UFA model has a "Marque" box and a "Type" box; the CFC one has a single
            // "Marque / Type".
            'deviceLabel' => trim(($laptop?->getBrand() ?? '').' '.($laptop?->getModel() ?? '')),
            'serialNumber' => $laptop?->getSerialNumber() ?? '',
            'conditionName' => $loan->getLentConditionType()?->getName() ?? '',
            'accessories' => $loan->getLentAccessories() ?? '',
            'replacementValue' => self::money($laptop?->getReplacementValue()),
            'lentAt' => self::date($loan->getLentAt()),
            'dueAt' => self::date($loan->getDueAt()),
            'city' => $formationCenter?->getCity() ?? '',
            'returnedAt' => self::date($loan->getReturnedAt()),
            'returnNotes' => $loan->getReturnStateNotes() ?? '',
            'returnConditionSlot' => self::conditionSlot($loan->getReturnConditionType()?->getName()),
            'returnAccessoryConditionSlot' => self::conditionSlot($loan->getReturnAccessoryConditionType()?->getName()),
        ];
    }

    /**
     * Which of the restitution form's five boxes a condition name ticks, or null for none.
     *
     * Case, accents and stray spacing are ignored - "Très bon état" and "tres bon etat" are the same
     * name typed twice, not two names. Anything else returns null and the line is left blank for a
     * hand-written tick: a near-miss must never tick an approximate box.
     */
    public static function conditionSlot(?string $conditionName): ?int
    {
        if (null === $conditionName) {
            return null;
        }

        $slot = array_search(self::normalize($conditionName), self::CONDITION_SLOTS, true);

        return false === $slot ? null : $slot;
    }

    private static function normalize(string $name): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);

        if (!\is_string($ascii)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $ascii));
    }

    private static function date(?\DateTimeImmutable $date): string
    {
        return $date?->format('d/m/Y') ?? '';
    }

    /**
     * "500.00" -> "500,00". Doctrine hydrates a DECIMAL column as a string, and it stays one all the
     * way to the paper: number_format() would route the amount through a float on its way to being
     * printed, which is the one thing money must never do.
     */
    private static function money(?string $amount): string
    {
        if (null === $amount || 1 !== preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $matches)) {
            return '';
        }

        [, $sign, $units] = $matches;
        $cents = str_pad(substr($matches[3] ?? '', 0, 2), 2, '0');
        $grouped = trim(chunk_split(strrev($units), 3, ' '));

        return $sign.strrev($grouped).','.$cents;
    }
}
