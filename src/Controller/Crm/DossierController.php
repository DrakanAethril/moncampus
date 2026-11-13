<?php

declare(strict_types=1);

namespace App\Controller\Crm;

use App\Attribute\RequiresFeature;
use App\Entity\CrmDossier;
use App\Entity\CrmDossierPiece;
use App\Entity\CrmPiece;
use App\Entity\Option;
use App\Entity\Program;
use App\Enum\CrmJournalKind;
use App\Enum\CrmModality;
use App\Enum\CrmPath;
use App\Enum\CrmPhase;
use App\Enum\CrmSituation;
use App\Enum\Feature;
use App\Repository\CrmDossierRepository;
use App\Repository\CrmOriginRepository;
use App\Service\Crm\CrmCandidateInput;
use App\Service\Crm\CrmDossierInput;
use App\Service\Crm\CrmDossierWriter;
use App\Service\Crm\CrmRefusal;
use App\Service\Crm\CrmSearch;
use App\Service\Crm\CrmSettings;
use App\Service\QueryValue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A dossier and what is done on it: open one, correct it, the candidate's details, the origin, the
 * situation, the modality, the SFP status, the refund, the journal, deleting it. Every write goes
 * through App\Service\Crm\CrmDossierWriter; the dialogs are fragments (CrmControllerTrait).
 */
#[RequiresFeature(Feature::Crm)]
class DossierController extends AbstractController
{
    use CrmControllerTrait;

    public function __construct(
        private readonly CrmSettings $settings,
        private readonly CrmDossierWriter $writer,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly CrmOriginRepository $origins,
    ) {
    }

    #[Route(path: '/crm/dossiers/{id}', name: 'app_crm_dossier', methods: ['GET'])]
    public function show(CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);

        return $this->render('crm/dossier.html.twig', [
            'dossier' => $dossier,
            'progress' => $this->settings->progress($dossier),
            'program' => $dossier->getProgram(),
            'year' => $dossier->getProgram()->getSchoolYear(),
            'settings' => $this->settings,
            'others' => array_values(array_filter($dossier->getCandidate()->getDossiers()->toArray(), static fn (CrmDossier $d): bool => $d !== $dossier)),
            'teamKinds' => CrmJournalKind::teamKinds(),
            'today' => $this->writer->today(),
        ]);
    }

    #[Route(path: '/crm/new', name: 'app_crm_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $year = $this->workYear($request, $this->settings);
        if (null === $year) {
            throw $this->createNotFoundException();
        }
        $programs = $this->settings->followedPrograms($year);

        $parameters = ['year' => $year, 'programs' => $programs, 'origins' => $this->origins->findBy([], ['position' => 'ASC']), 'settings' => $this->settings, 'modalities' => CrmModality::cases(), 'paths' => CrmPath::cases()];

        if ($request->isMethod('GET')) {
            $program = $this->programAmong($programs, QueryValue::nullableInt($request, 'program'));

            return $this->dialog('crm/dialog/new.html.twig', $parameters + ['values' => [
                'program' => $program?->getId(),
                'lastName' => mb_strtoupper(QueryValue::trimmed($request, 'last')),
                'firstName' => QueryValue::trimmed($request, 'first'),
                'path' => CrmPath::Enrolment->value,
                'modality' => null === $program ? CrmModality::Initial->value : $this->settings->defaultModality($program)->value,
            ]]);
        }

        $this->assertCsrf($request);
        $values = $request->request->all();
        try {
            $program = $this->programAmong($programs, (int) $request->request->getString('program'));
            if (null === $program) {
                throw CrmRefusal::because('crmRefusalProgramMissing');
            }
            $dossier = $this->writer->open(
                $this->candidateFrom($request),
                new CrmDossierInput(
                    $program,
                    $this->optionOf($program, (int) $request->request->getString('option')),
                    CrmPath::tryFrom($request->request->getString('path')) ?? CrmPath::Enrolment,
                    CrmModality::tryFrom($request->request->getString('modality')),
                    self::originsFrom($request, $this->origins->findBy([], ['position' => 'ASC'])),
                    $request->request->getString('prior'),
                ),
                $this->currentUser(),
            );
        } catch (CrmRefusal $refusal) {
            return $this->dialog('crm/dialog/new.html.twig', $parameters + ['values' => $values], $refusal, $this->translator);
        }
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('crmDossierOpenedFlash', ['%name%' => $dossier->getCandidate()->getFullName()]));

        return $this->redirect($this->dossierUrl($dossier));
    }

    /** What the new dossier's mail and name already are in the year: a refusal, or a warning. */
    #[Route(path: '/crm/new/check', name: 'app_crm_new_check', methods: ['GET'])]
    public function check(Request $request, CrmDossierRepository $dossiers): Response
    {
        $year = $this->workYear($request, $this->settings);
        $programs = null === $year ? [] : $this->settings->followedPrograms($year);
        $program = $this->programAmong($programs, QueryValue::nullableInt($request, 'program'));
        $mail = mb_strtolower(QueryValue::trimmed($request, 'mail'));
        $last = QueryValue::trimmed($request, 'last');
        $first = QueryValue::trimmed($request, 'first');

        $taken = null !== $program && '' !== $mail ? $dossiers->findHoldingMail($program, $mail) : null;
        $elsewhere = [];
        $homonyms = [];
        foreach ('' === $mail && ('' === $last || '' === $first) ? [] : $dossiers->forPrograms($programs) as $dossier) {
            $candidate = $dossier->getCandidate();
            if ('' !== $mail && $candidate->getEmail() === $mail && $dossier->getProgram() !== $program) {
                $elsewhere[] = $dossier;
            }
            if ('' !== $last && '' !== $first && CrmSearch::normalize($candidate->getLastName()) === CrmSearch::normalize($last) && CrmSearch::normalize($candidate->getFirstName()) === CrmSearch::normalize($first)) {
                $homonyms[] = $dossier;
            }
        }

        return $this->render('crm/_new_check.html.twig', ['taken' => $taken, 'elsewhere' => $elsewhere, 'homonyms' => $homonyms]);
    }

    #[Route(path: '/crm/dossiers/{id}/correct', name: 'app_crm_dossier_correct', methods: ['GET', 'POST'])]
    public function correct(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $year = $dossier->getProgram()->getSchoolYear();
        $programs = null === $year ? [] : $this->settings->followedPrograms($year);
        if (!\in_array($dossier->getProgram(), $programs, true)) {
            $programs[] = $dossier->getProgram();
        }
        $origins = $this->origins->findBy([], ['position' => 'ASC']);
        $parameters = ['dossier' => $dossier, 'programs' => $programs, 'origins' => $origins, 'settings' => $this->settings, 'paths' => CrmPath::cases()];

        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/correct.html.twig', $parameters);
        }

        $this->assertCsrf($request);
        try {
            $program = $this->programAmong($programs, (int) $request->request->getString('program')) ?? $dossier->getProgram();
            $this->writer->correct($dossier, new CrmDossierInput(
                $program,
                $this->optionOf($program, (int) $request->request->getString('option')) ?? ($program === $dossier->getProgram() && !$this->settings->asksOption($program) ? $dossier->getOption() : null),
                CrmPath::tryFrom($request->request->getString('path')) ?? $dossier->getPath(),
                null,
                self::originsFrom($request, $origins),
                $request->request->getString('prior'),
            ), $this->currentUser());
        } catch (CrmRefusal $refusal) {
            return $this->dialog('crm/dialog/correct.html.twig', $parameters, $refusal, $this->translator);
        }
        $this->entityManager->flush();

        return $this->redirect($this->dossierUrl($dossier));
    }

    #[Route(path: '/crm/dossiers/{id}/candidate', name: 'app_crm_dossier_candidate', methods: ['GET', 'POST'])]
    public function candidate(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $parameters = ['dossier' => $dossier, 'candidate' => $dossier->getCandidate()];
        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/candidate.html.twig', $parameters);
        }

        $this->assertCsrf($request);
        try {
            $this->writer->updateCandidate($dossier->getCandidate(), $this->candidateFrom($request));
        } catch (CrmRefusal $refusal) {
            return $this->dialog('crm/dialog/candidate.html.twig', $parameters + ['values' => $request->request->all()], $refusal, $this->translator);
        }
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('crmCandidateSavedFlash'));

        return $this->redirect($this->dossierUrl($dossier));
    }

    #[Route(path: '/crm/dossiers/{id}/origin', name: 'app_crm_dossier_origin', methods: ['GET', 'POST'])]
    public function origin(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $origins = $this->origins->findBy([], ['position' => 'ASC']);
        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/origin.html.twig', ['dossier' => $dossier, 'origins' => $origins]);
        }

        $this->assertCsrf($request);
        $this->writer->rewriteOriginAndPrior($dossier, self::originsFrom($request, $origins), $request->request->getString('prior'), $this->currentUser());
        $this->entityManager->flush();

        return $this->redirect($this->dossierUrl($dossier));
    }

    #[Route(path: '/crm/dossiers/{id}/situation', name: 'app_crm_dossier_situation', methods: ['GET', 'POST'])]
    public function situation(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        if ($request->isMethod('GET')) {
            return $this->dialog('crm/dialog/situation.html.twig', ['dossier' => $dossier, 'situations' => CrmSituation::cases(), 'deposit' => $this->depositOf($dossier)]);
        }

        $this->assertCsrf($request);
        $situation = CrmSituation::tryFrom($request->request->getString('situation')) ?? $dossier->getSituation();
        $this->writer->changeSituation($dossier, $situation, $request->request->getString('reason'), $request->request->getBoolean('refund'), $this->currentUser());
        $this->entityManager->flush();

        return $this->redirect($this->dossierUrl($dossier));
    }

    #[Route(path: '/crm/dossiers/{id}/modality', name: 'app_crm_dossier_modality', methods: ['GET', 'POST'])]
    public function modality(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        if ($request->isMethod('GET')) {
            $choices = [];
            foreach (CrmModality::cases() as $modality) {
                if ($modality === $dossier->getModality()) {
                    continue;
                }
                $simulated = clone $dossier;
                $simulated->setModality($modality);
                $progress = $this->settings->progress($simulated);
                $documents = array_filter($progress->applicableIn(CrmPhase::Documents), static fn ($piece): bool => !$progress->isOptional($piece));
                $choices[] = [
                    'modality' => $modality,
                    'expected' => \count($documents),
                    'already' => \count(array_filter($documents, static fn ($piece): bool => $progress->isDone($piece))),
                ];
            }
            $current = $this->settings->progress($dossier);
            $kept = \count(array_filter([...$current->applicableIn(CrmPhase::Documents), ...$current->applicableIn(CrmPhase::FollowUp)], static fn ($piece): bool => $current->isDone($piece) && \count($piece->getModalities()) < \count(CrmModality::cases())));

            return $this->dialog('crm/dialog/modality.html.twig', ['dossier' => $dossier, 'choices' => $choices, 'kept' => $kept, 'today' => $this->writer->today()]);
        }

        $this->assertCsrf($request);
        $modality = CrmModality::tryFrom($request->request->getString('modality'));
        if (null !== $modality) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('date')) ?: $this->writer->today();
            $this->writer->changeModality($dossier, $modality, $date, $request->request->getString('reason'), $this->currentUser());
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('crmModalityChangedFlash', ['%modality%' => $modality->label()]));
        }

        return $this->redirect($this->dossierUrl($dossier));
    }

    #[Route(path: '/crm/dossiers/{id}/sfp', name: 'app_crm_dossier_sfp', methods: ['POST'])]
    public function sfp(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $this->assertCsrf($request);
        $this->writer->setSfp($dossier, $request->request->getBoolean('sfp'), $this->currentUser());
        $this->entityManager->flush();

        return $this->redirect($this->dossierUrl($dossier));
    }

    #[Route(path: '/crm/dossiers/{id}/refund', name: 'app_crm_dossier_refund', methods: ['POST'])]
    public function refund(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $this->assertCsrf($request);
        $this->writer->markRefunded($dossier, $this->currentUser());
        $this->entityManager->flush();

        return $this->redirect($this->dossierUrl($dossier));
    }

    #[Route(path: '/crm/dossiers/{id}/journal', name: 'app_crm_dossier_journal', methods: ['POST'])]
    public function journal(Request $request, CrmDossier $dossier): Response
    {
        $this->crmProgram($dossier->getProgram(), $this->settings);
        $this->assertCsrf($request);
        try {
            $this->writer->addNote($dossier, CrmJournalKind::tryFrom($request->request->getString('kind')) ?? CrmJournalKind::Note, $request->request->getString('text'), $this->currentUser());
            $this->entityManager->flush();
        } catch (CrmRefusal $refusal) {
            foreach ($refusal->texts($this->translator) as $text) {
                $this->addFlash('danger', $text);
            }
        }

        return $this->redirect($this->dossierUrl($dossier).'#journal');
    }

    #[Route(path: '/crm/dossiers/{id}/delete', name: 'app_crm_dossier_delete', methods: ['POST'])]
    public function delete(Request $request, CrmDossier $dossier): Response
    {
        $program = $this->crmProgram($dossier->getProgram(), $this->settings);
        $this->assertCsrf($request);
        $name = $dossier->getCandidate()->getFullName();
        $this->writer->delete($dossier);
        $this->entityManager->flush();
        $this->addFlash('success', $this->translator->trans('crmDossierDeletedFlash', ['%name%' => $name]));

        return $this->redirectToRoute('app_crm_program', ['id' => $program->getId()]);
    }

    /** @param list<Program> $programs */
    private function programAmong(array $programs, ?int $id): ?Program
    {
        foreach ($programs as $program) {
            if (null !== $id && $program->getId() === $id) {
                return $program;
            }
        }

        return null;
    }

    private function optionOf(Program $program, int $id): ?Option
    {
        foreach ($program->getOptions() as $option) {
            if ($option->getId() === $id) {
                return $option;
            }
        }

        return null;
    }

    private function candidateFrom(Request $request): CrmCandidateInput
    {
        $guardians = [];
        foreach ([0, 1] as $index) {
            $guardian = $request->request->all()['guardians'][$index] ?? [];
            if (\is_array($guardian)) {
                $field = static fn (string $key): string => \is_scalar($guardian[$key] ?? null) ? (string) $guardian[$key] : '';
                $guardians[] = ['lastName' => $field('lastName'), 'firstName' => $field('firstName'), 'email' => $field('email'), 'phone' => $field('phone')];
            }
        }

        return CrmCandidateInput::of(
            $request->request->getString('lastName'),
            $request->request->getString('firstName'),
            $request->request->getString('email'),
            $request->request->getString('phone'),
            $request->request->getBoolean('foreign'),
            $guardians,
        );
    }

    private function depositOf(CrmDossier $dossier): ?CrmDossierPiece
    {
        foreach ($dossier->getPieces() as $entry) {
            if (CrmPiece::DEPOSIT === $entry->getPiece()->getCode()) {
                return $entry;
            }
        }

        return null;
    }
}
