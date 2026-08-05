<?php

namespace App\Enum;

/**
 * The four things a validator passes judgement on before a student's mailbox unlocks
 * (design_handoff_workflow_postulation): the application mail itself, the CV, the cover letter and
 * the signature.
 *
 * Four and not three: the signature travels on every mail the student will ever send to a company,
 * so it is read once here rather than discovered by the first recruiter.
 */
enum TrainingApplicationElement: string
{
    case Mail = 'mail';
    case Cv = 'cv';
    case CoverLetter = 'cover_letter';
    case Signature = 'signature';

    /** @return list<self> */
    public static function all(): array
    {
        return [self::Mail, self::Signature, self::Cv, self::CoverLetter];
    }

    public function labelKey(): string
    {
        return match ($this) {
            self::Mail => 'trainingApplicationElementMailLabel',
            self::Cv => 'trainingApplicationElementCvLabel',
            self::CoverLetter => 'trainingApplicationElementCoverLetterLabel',
            self::Signature => 'trainingApplicationElementSignatureLabel',
        };
    }

    /**
     * French makes the state agree with the element, so the screens cannot share one "validé": a
     * signature and a lettre de motivation are feminine, a mail and a CV are not.
     */
    public function stateLabelKey(string $state): string
    {
        return $this->isFeminine()
            ? sprintf('trainingElement%sFeminineLabel', ucfirst($state))
            : sprintf('trainingElement%sLabel', ucfirst($state));
    }

    public function isFeminine(): bool
    {
        return self::Signature === $this || self::CoverLetter === $this;
    }

    /** The two elements a student fixes by uploading a file again, rather than by rewriting. */
    public function isFile(): bool
    {
        return self::Cv === $this || self::CoverLetter === $this;
    }
}
