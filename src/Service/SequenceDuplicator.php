<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FileLibraryNode;
use App\Entity\LibraryBlocTag;
use App\Entity\LibraryNiveauTag;
use App\Entity\LibraryOptionTag;
use App\Entity\LibraryResource;
use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Entity\User;
use App\Enum\LibraryResourceSourceType;
use App\Repository\LibraryBlocTagRepository;
use App\Repository\LibraryNiveauTagRepository;
use App\Repository\LibraryOptionTagRepository;
use App\Repository\SequenceTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Dupliquer chez moi » for a séquence - the deep copy that makes the recipient the owner
 * (design/validated/content-sharing-between-teachers.md).
 *
 * Four things this does that a naive copy would get wrong:
 *
 * - **the quota is asked once, with the sum, before anything is written.** If it does not fit,
 *   nothing is - not the séquence, not the folders, not the first files that would have fitted.
 *   Asking per file is exactly how a partial write happens, and a partial write looks like a
 *   success;
 * - **every file gets a real second S3 object, with its own key.** « Un lien est une référence »
 *   holds *inside* one library; here two are crossed, and the author deleting their copy must never
 *   empty the recipient's folder. `file_library_node.storage_key` being UNIQUE would refuse the
 *   shortcut anyway - after the copy had already happened;
 * - **the tags travel as text.** Niveau/Option/Bloc are a private vocabulary per teacher
 *   (App\Entity\AbstractLibraryTag), so the labels are re-resolved into the recipient's own rows
 *   rather than pointed at the author's;
 * - **the quizzes do not travel.** A quiz attached to the séquence is a shareable item of its own,
 *   and duplicating it here would put a copy in a library that never asked for it.
 *
 * Nothing about the source is touched, ever: the author keeps theirs, intact.
 *
 * @phpstan-import-type DuplicationPlan from ContentShareDuplicationPlanner
 * @phpstan-import-type PlannedResource from ContentShareDuplicationPlanner
 */
class SequenceDuplicator
{
    /** The recipient's own library prefix - the copies are ordinary library files from now on. */
    private const string COPY_PREFIX = FileLibraryNodeManager::UPLOAD_PREFIX;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ContentShareDuplicationPlanner $planner,
        private readonly FileUploadService $fileUploadService,
        private readonly FileLibraryNodeManager $nodes,
        private readonly FileLibraryQuota $quota,
        private readonly LibraryTagResolver $tagResolver,
        private readonly LibraryNiveauTagRepository $niveauTags,
        private readonly LibraryOptionTagRepository $optionTags,
        private readonly LibraryBlocTagRepository $blocTags,
        private readonly SequenceTemplateRepository $sequences,
    ) {
    }

    /**
     * What the duplication will create and what it will weigh - the confirmation screen's whole
     * content, and the number the quota is asked about.
     *
     * @return DuplicationPlan
     */
    public function plan(SequenceTemplate $source): array
    {
        $seances = [];

        foreach ($source->getSeanceTemplates() as $seance) {
            $phaseResources = [];

            foreach ($seance->getSeancePhaseTemplates() as $phase) {
                foreach ($phase->getLibraryResources() as $resource) {
                    $phaseResources[] = $this->describe($resource);
                }
            }

            $seances[] = [
                'title' => (string) $seance->getTitre(),
                'resources' => $this->describeAll($seance->getLibraryResources()),
                'phaseResources' => $phaseResources,
            ];
        }

        return $this->planner->plan((string) $source->getTitre(), $this->describeAll($source->getLibraryResources()), $seances);
    }

    /**
     * The write, in one unit of work.
     *
     * The plan is recomputed here rather than taken from the confirmation screen: between the GET
     * and the POST the séquence may have gained a séance, and the quota question must be asked about
     * what is actually going to be written.
     *
     * @param FileLibraryNode|null $destination the folder the copies land under - null is the
     *                                          library root, which is the default the screen offers
     *
     * @throws ContentShareQuotaException when it does not fit; nothing has been written
     */
    public function duplicate(SequenceTemplate $source, User $recipient, ?FileLibraryNode $destination): SequenceTemplate
    {
        $plan = $this->plan($source);

        // Once, with the sum, and before a single row or object exists.
        if (!$this->quota->accepts($recipient, $plan['totalBytes'])) {
            throw new ContentShareQuotaException($plan['totalBytes'], $this->quota->remainingBytes($recipient));
        }

        $nodeBySourceKey = $this->createFolderTree($plan, $recipient, $destination);
        $copy = $this->copySequence($source, $recipient);

        foreach ($source->getLibraryResources() as $resource) {
            $this->copyResource($resource, $recipient, $nodeBySourceKey, static fn (LibraryResource $new): mixed => $new->setSequenceTemplate($copy));
        }

        foreach ($source->getSeanceTemplates() as $seance) {
            $seanceCopy = $this->copySeance($seance, $copy);

            foreach ($seance->getLibraryResources() as $resource) {
                $this->copyResource($resource, $recipient, $nodeBySourceKey, static fn (LibraryResource $new): mixed => $new->setSeanceTemplate($seanceCopy));
            }

            foreach ($seance->getSeancePhaseTemplates() as $phase) {
                $phaseCopy = $this->copyPhase($phase, $seanceCopy);

                foreach ($phase->getLibraryResources() as $resource) {
                    $this->copyResource($resource, $recipient, $nodeBySourceKey, static fn (LibraryResource $new): mixed => $new->setSeancePhaseTemplate($phaseCopy));
                }
            }
        }

        $this->entityManager->flush();

        return $copy;
    }

    /**
     * The folders of the plan, and one real second object per file.
     *
     * The S3 copies happen here, after the quota has answered. A failure part-way rolls the
     * transaction back and leaves orphan objects in the bucket - which is what
     * App\Command\PurgeUploadsCommand and the deferred deletion already handle, so no new mechanism
     * is needed for it.
     *
     * @param DuplicationPlan $plan
     *
     * @return array<string, FileLibraryNode> the new node, by the source object's storage key
     */
    private function createFolderTree(array $plan, User $recipient, ?FileLibraryNode $destination): array
    {
        $folders = [];
        $nodeBySourceKey = [];

        foreach ($plan['folders'] as $index => $planned) {
            $parent = null === $planned['parentIndex'] ? $destination : $folders[$planned['parentIndex']];
            $folders[$index] = $this->nodes->createFolder($recipient, $parent, $planned['name']);

            foreach ($planned['files'] as $file) {
                $sourceKey = (string) $file['storageKey'];

                // The same object attached twice (séquence level and séance level) is copied once:
                // two nodes pointing at one object is precisely the reference this design refuses.
                if (isset($nodeBySourceKey[$sourceKey])) {
                    continue;
                }

                $nodeBySourceKey[$sourceKey] = $this->copyObject($sourceKey, $file, $recipient, $folders[$index]);
            }
        }

        return $nodeBySourceKey;
    }

    /** @param PlannedResource $file */
    private function copyObject(string $sourceKey, array $file, User $recipient, FileLibraryNode $folder): FileLibraryNode
    {
        $extension = pathinfo($sourceKey, \PATHINFO_EXTENSION);
        $newKey = self::COPY_PREFIX.bin2hex(random_bytes(16)).('' !== $extension ? '.'.$extension : '');

        $this->fileUploadService->copy($sourceKey, $newKey);

        $name = $file['label'];

        // A resource's label is free text and may carry no extension at all; the file library sorts
        // and previews on the name, so the extension of the object it actually holds is added back.
        if ('' !== $extension && !str_ends_with(mb_strtolower($name), '.'.mb_strtolower($extension))) {
            $name .= '.'.$extension;
        }

        return $this->nodes->createFile(
            $recipient,
            $folder,
            $name,
            $newKey,
            $name,
            $this->fileUploadService->mimeType($sourceKey),
            $file['bytes'],
        );
    }

    private function copySequence(SequenceTemplate $source, User $recipient): SequenceTemplate
    {
        $copy = new SequenceTemplate($recipient);
        $copy->setTitre($source->getTitre());
        $copy->setOrder(\count($this->sequences->findForTeacher($recipient)) + 1);
        $copy->setCapacitesAttendues($source->getCapacitesAttendues());
        $copy->setPreRequis($source->getPreRequis());
        $copy->setObjectifs($source->getObjectifs());
        $copy->setTransversalites($source->getTransversalites());
        $copy->setSituationProblematique($source->getSituationProblematique());
        $copy->setSupportsGeneraux($source->getSupportsGeneraux());
        $copy->setDifferentiation($source->getDifferentiation());
        $copy->setWatchPoints($source->getWatchPoints());

        $this->applyTags($copy, $source, $recipient);
        $this->entityManager->persist($copy);

        return $copy;
    }

    private function copySeance(SeanceTemplate $source, SequenceTemplate $sequence): SeanceTemplate
    {
        $copy = new SeanceTemplate($sequence);
        $copy->setOrdre($source->getOrdre());
        $copy->setTitre($source->getTitre());
        $copy->setDuree($source->getDuree());
        $copy->setEvaluationNature($source->getEvaluationNature());
        $copy->setObjectifs($source->getObjectifs());
        $copy->setAvantDescription($source->getAvantDescription());
        $copy->setApresDescription($source->getApresDescription());
        $copy->setMaterials($source->getMaterials());
        $copy->setWatchPoints($source->getWatchPoints());
        $copy->setCahierDeTexteDescription($source->getCahierDeTexteDescription());
        $copy->setIsOptional($source->isOptional());
        $copy->setOptionalNote($source->getOptionalNote());

        $this->entityManager->persist($copy);

        return $copy;
    }

    private function copyPhase(SeancePhaseTemplate $source, SeanceTemplate $seance): SeancePhaseTemplate
    {
        $copy = new SeancePhaseTemplate($seance);
        $copy->setOrdre($source->getOrdre());
        $copy->setNom($source->getNom());
        $copy->setDuree($source->getDuree());
        $copy->setContenu($source->getContenu());
        $copy->setObjectifs($source->getObjectifs());
        $copy->setEnseignant($source->getEnseignant());
        $copy->setEtudiant($source->getEtudiant());
        $copy->setMoyensSupports($source->getMoyensSupports());
        $copy->setDifficultes($source->getDifficultes());

        $this->entityManager->persist($copy);

        return $copy;
    }

    /**
     * The new resource row. It points at the recipient's own node **and carries its own
     * `storage_key`, copied from that node** - the rule that makes the whole file library cheap,
     * because it means no reader changes.
     *
     * @param array<string, FileLibraryNode>       $nodeBySourceKey
     * @param \Closure(LibraryResource): mixed     $attach
     */
    private function copyResource(LibraryResource $source, User $recipient, array $nodeBySourceKey, \Closure $attach): void
    {
        $copy = new LibraryResource($recipient, (string) $source->getLabel());
        $copy->setType($source->getType());

        if (LibraryResourceSourceType::Upload === $source->getType()) {
            $node = $nodeBySourceKey[(string) $source->getStorageKey()] ?? null;

            // A resource whose object has vanished from the bucket has no node to point at: the copy
            // is skipped rather than written as a row that downloads nothing.
            if (null === $node) {
                return;
            }

            $copy->setLibraryNode($node);
            $copy->setStorageKey($node->getStorageKey());
        } else {
            $copy->setUrl($source->getUrl());
        }

        $this->applyResourceTags($copy, $source, $recipient);

        $attach($copy);
        $this->entityManager->persist($copy);
    }

    private function applyTags(SequenceTemplate $copy, SequenceTemplate $source, User $recipient): void
    {
        $copy->setNiveau($this->tagResolver->resolveOne($this->niveauTags, LibraryNiveauTag::class, $recipient, $source->getNiveau()?->getLabel()));
        $copy->setOption($this->tagResolver->resolveOne($this->optionTags, LibraryOptionTag::class, $recipient, $source->getOption()?->getLabel()));

        foreach ($this->tagResolver->resolveMany($this->blocTags, LibraryBlocTag::class, $recipient, $this->labelsOf($source->getBlocs())) as $bloc) {
            $copy->addBloc($bloc);
        }
    }

    private function applyResourceTags(LibraryResource $copy, LibraryResource $source, User $recipient): void
    {
        $copy->setNiveau($this->tagResolver->resolveOne($this->niveauTags, LibraryNiveauTag::class, $recipient, $source->getNiveau()?->getLabel()));
        $copy->setOption($this->tagResolver->resolveOne($this->optionTags, LibraryOptionTag::class, $recipient, $source->getOption()?->getLabel()));

        foreach ($this->tagResolver->resolveMany($this->blocTags, LibraryBlocTag::class, $recipient, $this->labelsOf($source->getBlocs())) as $bloc) {
            $copy->addBloc($bloc);
        }
    }

    /**
     * @param iterable<LibraryBlocTag> $tags
     *
     * @return list<string>
     */
    private function labelsOf(iterable $tags): array
    {
        $labels = [];

        foreach ($tags as $tag) {
            $labels[] = (string) $tag->getLabel();
        }

        return $labels;
    }

    /**
     * @param iterable<LibraryResource> $resources
     *
     * @return list<PlannedResource>
     */
    private function describeAll(iterable $resources): array
    {
        $described = [];

        foreach ($resources as $resource) {
            $described[] = $this->describe($resource);
        }

        return $described;
    }

    /**
     * One resource as the planner reads it. The byte count comes from the linked library node when
     * there is one, and from the bucket itself otherwise: a LibraryResource written before the file
     * library existed carries a key and no size, and the sum has to be right for both.
     *
     * @return PlannedResource
     */
    private function describe(LibraryResource $resource): array
    {
        if (LibraryResourceSourceType::Upload !== $resource->getType()) {
            return ['label' => (string) $resource->getLabel(), 'storageKey' => null, 'bytes' => 0];
        }

        $key = (string) $resource->getStorageKey();
        $bytes = $resource->getLibraryNode()?->getSizeBytes() ?? $this->fileUploadService->size($key);

        return ['label' => (string) $resource->getLabel(), 'storageKey' => $key, 'bytes' => $bytes];
    }
}
