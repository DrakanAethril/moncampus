<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ProgramRepository;

/**
 * The signature appended to every school mail (design_handoff_stage_alternance, screen 3f): name,
 * programme, etu address, phone, gold rule down the left.
 *
 * Built from what the platform already knows about the student, and not editable yet: screen 3f,
 * which lets them retouch it field by field and restore the school's default, is still to be
 * built. Until then every mail carries it anyway - that is what the compose mockup asks for, and a
 * generated signature beats no signature.
 */
class StudentSignatureBuilder
{
    public function __construct(
        private readonly ProgramRepository $programRepository,
    ) {
    }

    /**
     * @return array{name: string, formation: ?string, address: ?string, phone: ?string}
     */
    public function build(User $student, ?string $mailbox): array
    {
        $programs = $this->programRepository->findAllActiveForStudent($student);
        $program = $programs[0] ?? null;

        return [
            'name' => trim(($student->getFirstname() ?? '').' '.($student->getLastname() ?? '')) ?: $student->getUsername(),
            'formation' => null !== $program ? $program->getShortName() ?: $program->getName() : null,
            'address' => $mailbox,
            'phone' => $student->getPhoneNumber(),
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
        ]);

        return implode("\n", $lines);
    }
}
