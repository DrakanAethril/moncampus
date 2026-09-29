<?php

declare(strict_types=1);

namespace App\Service\Portfolio;

use App\Entity\Portfolio;
use App\Entity\PortfolioDeposit;
use App\Entity\PortfolioEvidence;
use App\Entity\PortfolioShowcase;
use App\Entity\User;
use App\Enum\PortfolioExam;
use App\Repository\ReferentialTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Déposer » - freezes an E5 or E6 dossier (design/validated/portfolio.md §6 screen 9, R8-R9).
 *
 * A deposit is a snapshot plus the files generated at that instant: for the E5, the synthesis
 * table (validated work only) filled into the session's official template when one is in service,
 * and its PDF; for the E6, the dossier (page de présentation + the fiches). Nothing about it changes
 * afterwards except the conformity check and the endorsement.
 *
 * Refused only for what the commission cannot do without: an E5 needs the address of the online
 * portfolio (a portfolio the commission cannot reach costs the candidate ten points), an E6 needs at
 * least one fiche. A deadline passed is recorded, never refused (R8); an incomplete bloc is shown,
 * never refused (R12).
 */
class PortfolioDepositor
{
    public function __construct(
        private readonly PortfolioContext $context,
        private readonly PortfolioSynthesis $synthesis,
        private readonly PortfolioPdfExporter $pdf,
        private readonly E5SynthesisXlsxWriter $xlsx,
        private readonly ReferentialTemplateRepository $templates,
        private readonly PortfolioFileStore $files,
        private readonly PortfolioShowcaseChecker $showcaseChecker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return list<string> the refusals, empty when the dossier may be deposited */
    public function refusals(Portfolio $portfolio, PortfolioExam $exam): array
    {
        $student = $portfolio->getStudent();
        $program = null === $student ? null : $this->context->currentProgram($student, $portfolio->getReferential());
        $refusals = [];

        if (null === $program) {
            $refusals[] = 'portfolioDepositNoProgramError';
        }

        if (PortfolioExam::E5 === $exam && null === $portfolio->getExternalUrl()) {
            $refusals[] = 'portfolioDepositUrlRequiredError';
        }

        if (PortfolioExam::E6 === $exam && [] === $this->showcasesToPrint($portfolio)) {
            $refusals[] = 'portfolioDepositShowcaseRequiredError';
        }

        return $refusals;
    }

    /**
     * @param \Closure(string, array<string, mixed>): string $render
     *
     * @throws \DomainException with the first refusal
     */
    public function deposit(Portfolio $portfolio, PortfolioExam $exam, User $by, \Closure $render): PortfolioDeposit
    {
        $refusals = $this->refusals($portfolio, $exam);
        if ([] !== $refusals) {
            throw new \DomainException($refusals[0]);
        }

        $student = $portfolio->getStudent();
        $program = null === $student ? null : $this->context->currentProgram($student, $portfolio->getReferential());
        if (null === $program) {
            throw new \DomainException('portfolioDepositNoProgramError');
        }

        $deadline = PortfolioExam::E5 === $exam ? $program->getPortfolioE5Deadline() : $program->getPortfolioE6Deadline();
        $folder = $portfolio->getId().'/deposits';
        $stamp = date('YmdHis').'-'.bin2hex(random_bytes(3));

        if (PortfolioExam::E5 === $exam) {
            $table = $this->synthesis->build($portfolio);
            $snapshot = $table->toSnapshot() + [
                'attestations' => array_map(static fn (PortfolioEvidence $evidence): array => [
                    'id' => $evidence->getId(), 'label' => $evidence->getLabel(), 'kind' => $evidence->getKind()->value,
                ], $portfolio->getAttestations()->toArray()),
                'complete' => $table->isComplete(),
            ];
            $deposit = new PortfolioDeposit($portfolio, $program, $exam, $by, $deadline, $snapshot);
            $deposit->setPdfKey($this->files->put($this->pdf->synthesis($table, $render), $folder, 'e5-'.$stamp.'.pdf'));

            $template = null === $portfolio->getReferential() ? null : $this->templates->findInService($portfolio->getReferential(), $program->getPortfolioExamSession());
            if (null !== $template) {
                $local = $this->files->localCopy($template->getFileKey());
                try {
                    $deposit->setXlsxKey($this->files->put($this->xlsx->write($local, $template->getAnchors(), $table), $folder, 'e5-'.$stamp.'.xlsx'));
                } finally {
                    @unlink($local);
                }
            }
        } else {
            $showcases = $this->showcasesToPrint($portfolio);
            $coverage = $this->showcaseChecker->check($this->context->claimableBlocks($portfolio)['showcase'], $portfolio->getShowcases());
            $snapshot = [
                'showcases' => array_map(static fn (PortfolioShowcase $showcase): array => ['number' => $showcase->getNumber(), 'state' => $showcase->getState()->value] + PortfolioSnapshot::ofShowcase($showcase), $showcases),
                'complete' => $coverage['complete'],
            ];
            $deposit = new PortfolioDeposit($portfolio, $program, $exam, $by, $deadline, $snapshot);
            $deposit->setPdfKey($this->files->put($this->pdf->e6Dossier($portfolio, $showcases, $render), $folder, 'e6-'.$stamp.'.pdf'));
        }

        $this->entityManager->persist($deposit);
        $this->entityManager->flush();

        return $deposit;
    }

    /**
     * The fiches an E6 dossier prints: the validated ones - the équipe does not transmit a fiche
     * nobody has validated.
     *
     * @return list<PortfolioShowcase>
     */
    public function showcasesToPrint(Portfolio $portfolio): array
    {
        return array_values(array_filter($portfolio->getShowcases()->toArray(), static fn (PortfolioShowcase $showcase): bool => $showcase->isValidated()));
    }
}
