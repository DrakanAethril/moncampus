<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\AssignmentSubmission;
use App\Entity\EngagementDeclaration;
use App\Entity\InternshipTutorLink;
use App\Entity\Portfolio;
use App\Entity\PortfolioAchievement;
use App\Entity\PortfolioClaim;
use App\Entity\PortfolioEvidence;
use App\Entity\User;
use App\Enum\EngagementState;
use App\Enum\PortfolioSetting;
use App\Repository\EngagementDeclarationRepository;
use App\Repository\InternshipTutorLinkRepository;
use App\Service\Rncp\ReferentialLabelMatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the rest of the platform already knows, offered to a réalisation (design/validated/portfolio.md §12):
 *
 * - **the alternance / the internship** - the employer's name and town and the contract's dates,
 *   to pre-fill a workplace réalisation;
 * - **a piece of work handed in** - « Ajouter à mon portfolio » opens a draft titled after the work,
 *   with the dépôt as its first piece of evidence;
 * - **the validated engagements** of the campus game (certifications, representation, projects…),
 *   imported as drafts claiming « Organiser son développement professionnel » - which also prepares
 *   the optional EF4.
 *
 * Every one of them creates a **draft**: nothing reaches a validateur without the student writing
 * it and submitting it. The validation of the source (a dépôt, an engagement) is never the
 * validation of the réalisation.
 */
class PortfolioPrefill
{
    public function __construct(
        private readonly InternshipTutorLinkRepository $tutorLinks,
        private readonly EngagementDeclarationRepository $engagements,
        private readonly PortfolioContext $context,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{label: string, organisation: string, place: string, from: ?string, until: ?string}>
     */
    public function workplaceSources(User $student): array
    {
        $sources = [];
        foreach ($this->context->programsOf($student) as $program) {
            $link = $this->tutorLinks->findOneForStudentAndProgram($student, $program);
            $enterprise = $link?->getEnterprise();
            if (!$link instanceof InternshipTutorLink || null === $enterprise) {
                continue;
            }

            $sources[] = [
                'label' => $enterprise->getName().' · '.$program->getDisplayShortName(),
                'organisation' => $enterprise->getName(),
                'place' => (string) $enterprise->getCity(),
                'from' => $link->getContractStartDate()?->format('Y-m-d'),
                'until' => $link->getContractEndDate()?->format('Y-m-d'),
            ];
        }

        return $sources;
    }

    public function fromSubmission(Portfolio $portfolio, AssignmentSubmission $submission): PortfolioAchievement
    {
        $assignment = $submission->getAssignment();
        $achievement = new PortfolioAchievement($portfolio);
        $achievement->setTitle(mb_substr((string) ($assignment?->getTitle() ?? $this->translator->trans('portfolioFromSubmissionDefaultTitle')), 0, 255));
        $achievement->setSetting(PortfolioSetting::Training);
        $submittedAt = $submission->getSubmittedAt();
        $achievement->setStartsOn($submittedAt?->setTime(0, 0));
        $achievement->setEndsOn($submittedAt?->setTime(0, 0));
        $achievement->setSourceSubmission($submission);
        PortfolioEvidence::submission($achievement, $this->translator->trans('portfolioFromSubmissionEvidenceLabel', ['%title%' => (string) $assignment?->getTitle()]), $submission);

        return $achievement;
    }

    /**
     * The student's validated engagements not imported yet.
     *
     * @return list<EngagementDeclaration>
     */
    public function engagementCandidates(Portfolio $portfolio): array
    {
        $student = $portfolio->getStudent();
        if (null === $student) {
            return [];
        }

        $imported = [];
        foreach ($portfolio->getAchievements() as $achievement) {
            if (null !== $achievement->getSourceEngagement()) {
                $imported[(int) $achievement->getSourceEngagement()->getId()] = true;
            }
        }

        $candidates = [];
        foreach ($this->context->programsOf($student) as $program) {
            foreach ($this->engagements->findForStudent($student, $program) as $engagement) {
                if (EngagementState::Validated === $engagement->getState() && !isset($imported[(int) $engagement->getId()])) {
                    $candidates[(int) $engagement->getId()] = $engagement;
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * @return list<PortfolioAchievement> the drafts created
     */
    public function importEngagements(Portfolio $portfolio): array
    {
        $competency = null;
        foreach ($portfolio->getReferential()?->getSynthesisBlock()?->getCompetencies() ?? [] as $candidate) {
            if (ReferentialLabelMatcher::contains($candidate->getLabel(), 'développement professionnel')) {
                $competency = $candidate;
            }
        }

        $created = [];
        foreach ($this->engagementCandidates($portfolio) as $engagement) {
            $achievement = new PortfolioAchievement($portfolio);
            $kind = $this->translator->trans($engagement->getKind()->labelKey());
            $description = trim($engagement->getDescription());
            $achievement->setTitle(mb_substr($kind.('' === $description ? '' : ' : '.strtok($description, "\n")), 0, 255));
            $achievement->setSetting(PortfolioSetting::Training);
            $achievement->setStartsOn($engagement->getCreatedAt()->setTime(0, 0));
            $achievement->setEndsOn(($engagement->getReviewedAt() ?? $engagement->getCreatedAt())->setTime(0, 0));
            $achievement->setDescriptionHtml('' === $description ? null : '<p>'.nl2br(htmlspecialchars($description)).'</p>');
            $achievement->setSourceEngagement($engagement);

            if (null !== $competency) {
                new PortfolioClaim($achievement, $competency, $this->translator->trans('portfolioFromEngagementJustification', ['%kind%' => $kind]));
            }

            $created[] = $achievement;
        }

        return $created;
    }
}
