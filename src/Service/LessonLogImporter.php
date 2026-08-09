<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Assignment;
use App\Entity\LessonLog;
use App\Entity\LessonLogAttachment;
use App\Entity\LessonSession;
use App\Entity\SeanceInstance;
use App\Entity\User;
use App\Enum\LessonLogAttachmentSourceType;
use App\Enum\LessonLogSection;
use App\Repository\AssignmentCompletionRepository;
use App\Repository\AssignmentRepository;
use App\Repository\AssignmentSubmissionRepository;
use App\Repository\LessonLogRepository;
use App\Repository\LessonSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Importer depuis une séance » (design_handoff_cahier_de_texte 2a) : reprendre le cahier de texte
 * d'une séance déjà remplie - le même cours donné à l'autre groupe, ou l'an dernier - plutôt que de
 * le retaper.
 *
 * Copie profonde et non partage : la séance cible reçoit ses propres textes, documents et travaux,
 * qu'elle peut modifier sans toucher à la source. Rien n'arrive publié - ni les temps, qui gardent
 * la visibilité de la cible, ni les travaux, dépubliés à la copie : l'enseignant relit d'abord.
 */
class LessonLogImporter
{
    private const string ATTACHMENT_UPLOAD_PREFIX = 'lesson-logs/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LessonLogRepository $lessonLogRepository,
        private readonly LessonSessionRepository $lessonSessionRepository,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly AssignmentSubmissionRepository $submissionRepository,
        private readonly AssignmentCompletionRepository $completionRepository,
        private readonly FileUploadService $fileUploadService,
    ) {
    }

    /**
     * Les séances proposées à l'import pour une séance donnée, dans l'ordre de priorité de la
     * maquette : le même cours à un autre groupe cette année, puis l'année précédente.
     *
     * « Le même cours » se reconnaît au nom de la matière : deux groupes ont chacun leur propre
     * Topic, portant le même intitulé, et c'est le seul lien entre eux que le modèle offre.
     * Seules les séances dont le cahier de texte dit quelque chose sont proposées - importer un
     * cahier vide ne rendrait aucun service.
     *
     * @return list<array{session: LessonSession, kind: string}>
     */
    public function suggestionsFor(LessonSession $session): array
    {
        $candidates = $this->lessonSessionRepository->findComparableFilledSessions($session);

        $sameYear = [];
        $previousYears = [];
        foreach ($candidates as $candidate) {
            $isSameYear = $candidate->getProgram()?->getSchoolYear()?->getId() === $session->getProgram()?->getSchoolYear()?->getId();
            $isSameYear ? $sameYear[] = $candidate : $previousYears[] = $candidate;
        }

        $suggestions = [];
        if ([] !== $sameYear) {
            $suggestions[] = ['session' => $sameYear[0], 'kind' => 'otherGroup'];
        }
        if ([] !== $previousYears) {
            $suggestions[] = ['session' => $previousYears[0], 'kind' => 'previousYear'];
        }

        return $suggestions;
    }

    /** @return list<LessonSession> */
    public function browsableFor(LessonSession $session): array
    {
        return $this->lessonSessionRepository->findComparableFilledSessions($session);
    }

    /**
     * Recopie le cahier de texte de $source sur $target : les trois textes, les documents, les
     * travaux. Ce qui existe déjà sur la cible n'est pas écrasé mais complété - un texte déjà
     * saisi reste, les documents et travaux s'ajoutent.
     */
    public function import(LessonSession $source, LessonSession $target, User $actor): void
    {
        $sourceLog = $this->lessonLogRepository->findOneBySession($source);
        if (null === $sourceLog) {
            return;
        }

        $targetLog = $this->lessonLogRepository->findOneBySession($target);
        if (null === $targetLog) {
            // Première écriture sur cette séance : le cahier de texte naît de l'import, et c'est
            // celui qui importe qui en est l'auteur.
            $targetLog = new LessonLog($target);
            $targetLog->setCreatedBy($actor);
        }

        $targetLog->setLastUpdatedBy($actor);

        foreach (LessonLogSection::cases() as $section) {
            // Ne remplace que ce qui est vide : l'enseignant qui a déjà écrit quelque chose sur ce
            // temps a dit quelque chose que l'import n'a pas à effacer.
            if ('' === trim(strip_tags((string) $targetLog->getContent($section)))) {
                $this->setContent($targetLog, $section, $sourceLog->getContent($section));
            }
        }

        foreach ($sourceLog->getAttachments() as $attachment) {
            $this->entityManager->persist($this->copyAttachment($attachment, $targetLog));
        }

        $this->entityManager->persist($targetLog);

        foreach ($this->assignmentRepository->findForLessonSession($source) as $work) {
            $this->entityManager->persist($this->copyWork($work, $source, $target, $actor));
        }

        $this->entityManager->flush();
    }

    /**
     * Reprendre la séance de bibliothèque dont ce créneau est issu (première entrée du menu
     * d'import). Contrairement à l'import depuis une autre séance, celui-ci **remplace** : les
     * trois temps, les documents et les travaux sont refaits à partir de la séance source, qui fait
     * autorité. L'écran demande confirmation quand il y a déjà quelque chose à écraser.
     *
     * Rend le nombre de travaux conservés malgré tout, ceux qui portent déjà une production
     * étudiante - l'écran le dit à l'enseignant plutôt que de les faire disparaître en silence.
     *
     * Les trois temps se lisent sur la séance : le travail préparatoire, le cahier de texte
     * proprement dit - à défaut ses objectifs, plus grossiers mais mieux que rien - et le travail
     * donné après. Ses ressources deviennent les documents du temps « pendant ».
     */
    public function importFromLibrary(SeanceInstance $seance, LessonSession $target, User $actor): int
    {
        $targetLog = $this->lessonLogRepository->findOneBySession($target);
        if (null === $targetLog) {
            $targetLog = new LessonLog($target);
            $targetLog->setCreatedBy($actor);
        }

        $targetLog->setLastUpdatedBy($actor);
        $targetLog->setTravailAvantDescription($seance->getAvantDescription());
        $targetLog->setContenuRealise($seance->getCahierDeTexteDescription() ?: $seance->getObjectifs());
        $targetLog->setTravailApresDescription($seance->getApresDescription());

        foreach ($targetLog->getAttachments() as $existing) {
            $this->entityManager->remove($existing);
        }

        foreach ($seance->getLibraryResourceInstances() as $resource) {
            $copy = new LessonLogAttachment($targetLog, (string) $resource->getLabel());
            $copy->setType(LessonLogAttachmentSourceType::from($resource->getType()->value));
            $copy->setSection(LessonLogSection::During);
            $copy->setUrl($resource->getUrl());

            if (LessonLogAttachmentSourceType::Upload === $copy->getType() && null !== $resource->getStorageKey()) {
                $destination = self::ATTACHMENT_UPLOAD_PREFIX.uniqid('', true).'-'.basename($resource->getStorageKey());
                $this->fileUploadService->copy($resource->getStorageKey(), $destination);
                $copy->setStorageKey($destination);
            }

            $this->entityManager->persist($copy);
        }

        // La séance de bibliothèque ne porte pas de travaux : « remplacer » revient donc à retirer
        // ceux qui étaient là - sauf ceux sur lesquels un étudiant a déjà produit quelque chose.
        // Supprimer un dépôt ou une déclaration d'achèvement doit rester un geste délibéré de
        // l'enseignant, pas l'effet de bord d'un import.
        $kept = 0;
        foreach ($this->assignmentRepository->findForLessonSession($target) as $work) {
            if ($this->hasStudentProduction($work)) {
                ++$kept;
                continue;
            }

            $this->entityManager->remove($work);
        }

        $this->entityManager->persist($targetLog);
        $this->entityManager->flush();

        return $kept;
    }

    /**
     * Les identifiants des travaux d'une séance déjà commencés par un étudiant - ce que l'écran
     * doit savoir pour prévenir avant de les supprimer.
     *
     * @return list<int>
     */
    public function worksWithProduction(LessonSession $session): array
    {
        $ids = [];
        foreach ($this->assignmentRepository->findForLessonSession($session) as $work) {
            if ($this->hasStudentProduction($work)) {
                $ids[] = (int) $work->getId();
            }
        }

        return $ids;
    }

    /**
     * Un travail sur lequel un étudiant a déposé un fichier ou déclaré avoir fini. La suppression
     * du travail emporterait ces traces, ce qu'aucun import n'a à décider.
     */
    private function hasStudentProduction(Assignment $work): bool
    {
        return $this->submissionRepository->hasAnyForAssignment($work)
            || $this->completionRepository->hasAnyForAssignment($work);
    }

    /**
     * Y a-t-il déjà quelque chose à écraser ? Sert à ne demander confirmation que lorsque l'import
     * détruit vraiment quelque chose.
     */
    public function hasContent(LessonSession $session): bool
    {
        $log = $this->lessonLogRepository->findOneBySession($session);

        if (null !== $log) {
            foreach (LessonLogSection::cases() as $section) {
                if ('' !== trim(strip_tags((string) $log->getContent($section)))) {
                    return true;
                }
            }

            if (!$log->getAttachments()->isEmpty()) {
                return true;
            }
        }

        return [] !== $this->assignmentRepository->findForLessonSession($session);
    }

    private function setContent(LessonLog $log, LessonLogSection $section, ?string $content): void
    {
        match ($section) {
            LessonLogSection::Before => $log->setTravailAvantDescription($content),
            LessonLogSection::During => $log->setContenuRealise($content),
            LessonLogSection::After => $log->setTravailApresDescription($content),
        };
    }

    /**
     * Un document déposé est dupliqué dans le stockage, pas partagé : deux cahiers de texte
     * pointant le même fichier, c'est un fichier qui disparaît des deux dès qu'on le retire d'un
     * seul. Un lien externe, lui, se recopie tel quel - il ne nous appartient pas.
     */
    private function copyAttachment(LessonLogAttachment $source, LessonLog $targetLog): LessonLogAttachment
    {
        $copy = new LessonLogAttachment($targetLog, (string) $source->getLabel());
        $copy->setType($source->getType());
        $copy->setSection($source->getSection());
        $copy->setUrl($source->getUrl());

        if (LessonLogAttachmentSourceType::Upload === $source->getType() && null !== $source->getStorageKey()) {
            $destination = self::ATTACHMENT_UPLOAD_PREFIX.uniqid('', true).'-'.basename($source->getStorageKey());
            $this->fileUploadService->copy($source->getStorageKey(), $destination);
            $copy->setStorageKey($destination);
        }

        return $copy;
    }

    /**
     * Le travail est recopié dépublié, et son échéance suit le décalage entre les deux séances :
     * un compte rendu attendu une semaine après le TP reste attendu une semaine après le TP.
     */
    private function copyWork(Assignment $source, LessonSession $sourceSession, LessonSession $targetSession, User $actor): Assignment
    {
        $copy = new Assignment($targetSession->getProgram());
        $copy->setTitle($source->getTitle());
        $copy->setDescription($source->getDescription());
        $copy->setNature($source->getNature());
        $copy->setAcceptedFormats($source->getAcceptedFormats());
        $copy->setLessonSession($targetSession);
        $copy->setLessonLogSection($source->getLessonLogSection());
        $copy->setDueDate($this->shiftDueDate($source, $sourceSession, $targetSession));
        $copy->setCreatedBy($actor);

        // Le quiz ne suit pas : il appartient à la formation source, avec ses questions et ses
        // tentatives. C'est à l'enseignant de désigner celui de sa propre formation.
        $copy->setAudienceType($source->getAudienceType());
        foreach ($targetSession->getOptions() as $option) {
            $copy->addOption($option);
        }

        return $copy;
    }

    private function shiftDueDate(Assignment $source, LessonSession $sourceSession, LessonSession $targetSession): \DateTimeImmutable
    {
        $sourceStart = $sourceSession->getStartAt();
        $targetStart = $targetSession->getStartAt();
        $due = $source->getDueDate();

        if (null === $sourceStart || null === $targetStart || null === $due) {
            return $due ?? new \DateTimeImmutable('+7 days');
        }

        return $targetStart->add($sourceStart->diff($due));
    }
}
