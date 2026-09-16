<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Which paper convention a laptop loan is printed on: the CFA Aspect Aquitaine model for an
 * apprentice (UFA), the Beaupeyrat one for a continuing-education student (CFC) - or none at all,
 * for a machine handed to a member of staff (Interne).
 *
 * Carried by the loan rather than derived from the borrower on purpose - it pins the model chosen
 * when the loan was recorded, so a borrower who later changes programme keeps printing on the
 * document that was actually signed.
 *
 * Interne is the case where there is nothing to sign: the institution lending to its own people
 * signs no convention with itself. It is a type like the other two rather than an absence of type,
 * because the loan still has to be recorded, dated and given back - what changes is only that no
 * paper comes with it (see hasConvention(), and App\Service\LaptopLoanDocumentExporter which has no
 * model for it).
 */
enum LaptopLoanType: string
{
    case Ufa = 'ufa';
    case Cfc = 'cfc';
    case Interne = 'interne';

    /**
     * The type a borrower's situation calls for: an apprentice borrows under the UFA convention,
     * another student under the CFC one, and anyone who is not a student at all borrows internally.
     *
     * What makes a borrower an apprentice is being tagged with the alternance Modality in one of
     * their programmes (Modality::$isAlternance), not merely being enrolled in a programme that
     * runs an alternance track - see
     * ProgramStudentModalityRepository::findAlternanceProgramIdsForStudent().
     *
     * This only ever pre-selects the field. The operator has the last word, because the paper that
     * gets signed is a decision, not a lookup: a borrower with no modality recorded yet, or one
     * whose situation is changing, still has to be lent a machine today - and a teacher taking a
     * machine home for a class may well be lent one under a convention all the same.
     */
    public static function suggestFor(bool $isStudent, bool $isAlternant): self
    {
        if (!$isStudent) {
            return self::Interne;
        }

        return $isAlternant ? self::Ufa : self::Cfc;
    }

    /**
     * Whether a paper convention exists for this type at all - the one question the screens ask
     * before offering a document to print.
     *
     * Deliberately answered here rather than read off LaptopLoanDocumentExporter::supports(): the
     * exporter answers "is a model built", which for a *future* type could be a temporary no. This
     * answers "is there anything to sign", which for Interne is a permanent no.
     */
    public function hasConvention(): bool
    {
        return self::Interne !== $this;
    }

    /**
     * Whether this type of loan may be recorded with no return date at all.
     *
     * Only an internal loan may: a machine handed to a member of staff is often lent for as long as
     * they need it, and inventing a date for it would only produce an overdue loan nobody meant.
     * The two conventions are signed with a return date on the paper itself, so there is nothing to
     * make indefinite there.
     *
     * Kept next to hasConvention() because it is the same kind of answer - what the type itself
     * allows - and because the form, the validation and the screens must all read one rule.
     */
    public function allowsIndefiniteDuration(): bool
    {
        return self::Interne === $this;
    }

    /**
     * The values of the types above, for the front end - the checkbox is only offered on those.
     *
     * @return list<string>
     */
    public static function indefiniteDurationValues(): array
    {
        return array_values(array_map(
            static fn (self $type): string => $type->value,
            array_filter(self::cases(), static fn (self $type): bool => $type->allowsIndefiniteDuration()),
        ));
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Ufa => 'laptopLoanTypeUfaLabel',
            self::Cfc => 'laptopLoanTypeCfcLabel',
            self::Interne => 'laptopLoanTypeInternalLabel',
        };
    }
}
