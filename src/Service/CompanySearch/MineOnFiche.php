<?php

declare(strict_types=1);

namespace App\Service\CompanySearch;

use App\Entity\JobApplication;
use App\Entity\User;
use App\Enum\Feature;
use App\Repository\JobApplicationRepository;
use App\Security\FeatureAccess;

/**
 * « Vous et cette entreprise » on a fiche - the student's démarche about this establishment, or
 * the means to open one (« Garder ») or to point one they named by hand at it (« Rattacher »).
 * Nothing for anybody but a student whose Courrier pro is open: that is where démarches live.
 */
class MineOnFiche
{
    public function __construct(
        private readonly JobApplicationRepository $applications,
        private readonly FeatureAccess $features,
    ) {
    }

    /**
     * @return array{application: ?JobApplication, attachable: list<JobApplication>}|null
     */
    public function for(?User $user, bool $isStudent, ?string $siret): ?array
    {
        if (null === $user || !$isStudent || null === $siret || !$this->features->isEnabled(Feature::SchoolMail, $user)) {
            return null;
        }

        $application = $this->applications->findForStudentBySirets($user, [$siret])[$siret] ?? null;

        return [
            'application' => $application,
            'attachable' => null === $application
                ? array_values(array_filter($this->applications->findForStudent($user), static fn (JobApplication $candidate): bool => null === $candidate->getSiret()))
                : [],
        ];
    }
}
