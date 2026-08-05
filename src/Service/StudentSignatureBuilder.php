<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ProgramRepository;
use App\Repository\SchoolMailSignatureRepository;

/**
 * The signature appended to every school mail (design_handoff_stage_alternance, screens 3f and 3d):
 * name, programme, etu address, phone, LinkedIn/GitHub, gold rule down the left.
 *
 * Two layers, and the order matters: the school's default is computed from what the platform
 * already knows about the student, and it applies as long as the student has not edited anything.
 * The moment they save screen 3f, their own row wins field by field - screen 3f shows the effective
 * values, so what they see there is exactly what companies receive.
 *
 * An emptied field is stored as an empty string and drops that line from the signature, which is how
 * a student chooses not to send their phone number. Getting the computed default back is exactly
 * what "Restore the default signature" does, by deleting the row rather than rewriting it.
 */
class StudentSignatureBuilder
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
        private readonly SchoolMailSignatureRepository $signatureRepository,
    ) {
    }

    /**
     * @return array{name: string, formation: ?string, address: ?string, phone: ?string, linkedin: ?string, github: ?string}
     */
    public function build(User $student, ?string $mailbox): array
    {
        $defaults = $this->defaults($student, $mailbox);
        $custom = $this->signatureRepository->findOneForStudent($student);

        if (null === $custom) {
            return $defaults;
        }

        return [
            'name' => $this->override($custom->getFullName(), $defaults['name']) ?? $defaults['name'],
            'formation' => $this->override($custom->getProgramLabel(), $defaults['formation']),
            'address' => $this->override($custom->getEmailAddress(), $defaults['address']),
            'phone' => $this->override($custom->getPhone(), $defaults['phone']),
            'linkedin' => $this->override($custom->getLinkedinUrl(), $defaults['linkedin']),
            'github' => $this->override($custom->getGithubUrl(), $defaults['github']),
        ];
    }

    /**
     * The school's signature, untouched - what screen 3f shows as the reference and what the reset
     * button goes back to.
     *
     * @return array{name: string, formation: ?string, address: ?string, phone: ?string, linkedin: ?string, github: ?string}
     */
    public function defaults(User $student, ?string $mailbox): array
    {
        $programs = $this->programRepository->findAllActiveForStudent($student);
        $program = $programs[0] ?? null;

        return [
            'name' => trim(($student->getFirstname() ?? '').' '.($student->getLastname() ?? '')) ?: $student->getUsername(),
            'formation' => null !== $program ? $program->getShortName() ?: $program->getName() : null,
            'address' => $mailbox,
            'phone' => $student->getPhoneNumber(),
            // The platform knows nothing of either: they only ever come from screen 3f.
            'linkedin' => null,
            'github' => null,
        ];
    }

    /** The same signature as plain text, for the MIME's text part. */
    public function toText(array $signature): string
    {
        $lines = array_filter([
            $signature['name'],
            $signature['formation'],
            $signature['address'],
            $signature['phone'],
            trim(implode(' - ', array_filter([$signature['linkedin'] ?? null, $signature['github'] ?? null]))) ?: null,
        ]);

        return implode("\n", $lines);
    }

    /** null means "never edited, follow the default"; an empty string means "deliberately dropped". */
    private function override(?string $custom, ?string $default): ?string
    {
        if (null === $custom) {
            return $default;
        }

        return '' === trim($custom) ? null : $custom;
    }
}
