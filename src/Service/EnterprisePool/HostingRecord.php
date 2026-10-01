<?php

declare(strict_types=1);

namespace App\Service\EnterprisePool;

use App\Entity\EnterpriseHosting;
use App\Entity\InternshipTutorLink;
use App\Entity\Option;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\HostingKind;

/**
 * One stage or alternance of one of our students at a company, whichever side it is read from:
 * a contract of the UFA (`alternance`) or a stored hosting (`hosting`) - never both, R3 sees to it.
 */
final readonly class HostingRecord
{
    public function __construct(
        public HostingKind $kind,
        public int $yearStart,
        public ?Track $track,
        public ?Option $option,
        public ?User $student,
        public ?string $studentName,
        public ?string $missions,
        /** The tutor, as a name: a declared contact or a UFA tutor account. */
        public ?string $tutorName,
        public ?EnterpriseHosting $hosting = null,
        public ?InternshipTutorLink $alternance = null,
    ) {
    }

    public function isFromUfa(): bool
    {
        return null !== $this->alternance;
    }

    /** « 2024-2025 ». */
    public function schoolYear(): string
    {
        return $this->yearStart.'-'.($this->yearStart + 1);
    }

    /** « SIO · SLAM » - the filière, and the option when one is known. */
    public function domain(): string
    {
        $track = $this->track?->getName() ?? '—';
        $option = null !== $this->option ? ('' !== $this->option->getShortName() ? $this->option->getShortName() : $this->option->getName()) : null;

        return null !== $option && '' !== $option ? $track.' · '.$option : $track;
    }

    /** Who it was - for an administrator's eyes only (EnterpriseVoter::VIEW_HOSTED_STUDENTS). */
    public function studentLabel(): string
    {
        if (null !== $this->student) {
            return $this->student->getDisplayName() ?? $this->student->getUsername();
        }

        return $this->studentName ?? '—';
    }
}
