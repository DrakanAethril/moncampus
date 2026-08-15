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
 * « Importer depuis une séance » (design_handoff_cahier_de_texte 2a): take back the cahier de texte
 * of an already filled séance - the same lesson given to the other group, or last year - rather
 * than typing it again.
 *
 * A deep copy and not a share: the target séance gets its own texts, documents and assignments,
 * which it can edit without touching the source. Nothing arrives published - neither the parts,
 * which keep the target's visibility, nor the assignments, unpublished on copy: the teacher reads
 * it over first.
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
     * The séances offered for import for a given séance, in the mockup's order of priority: the same
     * lesson to another group this year, then the previous year.
     *
     * « The same lesson » is recognised by the matière name: two groups each have their own Topic,
     * carrying the same label, and that is the only link between them the model offers.
     * Only séances whose cahier de texte says something are offered - importing an empty one would
     * be no service at all.
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
     * Copies $source's cahier de texte onto $target: the three texts, the documents, the
     * assignments. What already exists on the target is not overwritten but completed - a text
     * already entered stays, documents and assignments are added.
     */
    public function import(LessonSession $source, LessonSession $target, User $actor): void
    {
        $sourceLog = $this->lessonLogRepository->findOneBySession($source);
        if (null === $sourceLog) {
            return;
        }

        $targetLog = $this->lessonLogRepository->findOneBySession($target);
        if (null === $targetLog) {
            // First write on this séance: the cahier de texte is born of the import, and whoever
            // imports is its author.
            $targetLog = new LessonLog($target);
            $targetLog->setCreatedBy($actor);
        }

        $targetLog->setLastUpdatedBy($actor);

        foreach (LessonLogSection::cases() as $section) {
            // Only replaces what is empty: a teacher who already wrote something on this part said
            // something the import has no business erasing.
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
     * Take back the library séance this slot came from (first entry of the import menu). Unlike the
     * import from another séance, this one **replaces**: the three parts, the documents and the
     * assignments are remade from the source séance, which is authoritative. The screen asks for
     * confirmation when there is already something to overwrite.
     *
     * Returns the number of assignments kept nonetheless, those already carrying a student
     * production - the screen tells the teacher rather than making them vanish silently.
     *
     * The three parts are read off the séance: the preparatory work, the cahier de texte proper -
     * failing that its objectives, coarser but better than nothing - and the work given afterwards.
     * Its resources become the documents of the « during » part.
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

        // The library séance carries no assignments: « replacing » therefore amounts to removing
        // the ones that were there - except those a student has already produced something on.
        // Deleting a submission or a completion declaration must stay a deliberate gesture by the
        // teacher, not the side effect of an import.
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
     * The identifiers of a séance's assignments already started by a student - what the screen needs
     * to know in order to warn before deleting them.
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
     * An assignment a student has submitted a file to or declared finished. Deleting the assignment
     * would carry those traces away, which no import has any business deciding.
     */
    private function hasStudentProduction(Assignment $work): bool
    {
        return $this->submissionRepository->hasAnyForAssignment($work)
            || $this->completionRepository->hasAnyForAssignment($work);
    }

    /**
     * Is there already something to overwrite? Used to ask for confirmation only when the import
     * really destroys something.
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
     * An uploaded document is duplicated in storage, not shared: two cahiers de texte pointing at
     * the same file means a file that disappears from both as soon as it is removed from one. An
     * external link, by contrast, is copied as is - it is not ours.
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
     * The assignment is copied unpublished, and its deadline follows the offset between the two
     * séances: a report due a week after the practical is still due a week after the practical.
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

        // The quiz does not follow: it belongs to the source program, with its questions and its
        // attempts. It is up to the teacher to designate the one from their own program.
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
