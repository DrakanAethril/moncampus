<?php

declare(strict_types=1);

namespace App\Service\OnlineCourse;

use App\Entity\FileLibraryNode;
use App\Entity\OnlineCourse;
use App\Entity\OnlineCourseMaterial;
use App\Entity\OnlineCourseMaterialRevision;
use App\Enum\OnlineCourseMaterialKind;
use App\Service\FileUploadService;
use App\Service\HelpSlug;
use App\Service\StagedUpload;
use App\Service\UploadIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Takes a file into a course's own folder, as a new revision of one of its materials
 * (design/validated/cours-en-ligne.md, §6). The screens and the Claude connector both write
 * through here, so the rules below hold whichever door a file came in by.
 *
 * - **A revision has a folder of its own**, `online-courses/{course}/{token}/{material}/r{n}/`, and
 *   a number that only grows. Replacing a material therefore changes the address of its bytes and
 *   never the address of the material: the CDN cannot serve a mix of two versions, and nothing has
 *   to be invalidated.
 * - **A library file is copied, not referenced.** Everywhere else on the platform a link to the
 *   library is a reference (App\Service\UploadIntake); here it would make deleting a file from the
 *   library take a public course offline. The copy is what lets the two live apart.
 * - **The revision before the live one is kept**, so that going back is a button; anything older is
 *   handed to the deferred purge, like every removal on this platform.
 */
class OnlineCourseMaterialStore
{
    public function __construct(
        private readonly UploadIntake $intake,
        private readonly FileUploadService $fileUploads,
        private readonly EntityManagerInterface $entityManager,
        private readonly HelpSlug $slug,
    ) {
    }

    /**
     * @throws OnlineCourseMaterialRefused
     */
    public function add(OnlineCourse $course, OnlineCourseMaterialKind $kind, UploadedFile|StagedUpload|FileLibraryNode $file, ?string $label = null): OnlineCourseMaterial
    {
        $this->assertAccepted($kind, $file);

        $material = new OnlineCourseMaterial($course, $kind, $this->uniqueSlug($course, $kind));
        $material->setLabel($label);
        $material->setPosition($this->nextPosition($course));
        $this->writeRevision($material, $file);

        $course->addMaterial($material);
        $course->touch();
        $this->entityManager->persist($material);

        return $material;
    }

    /**
     * A new revision of an existing material: same tab, same public address, new bytes.
     *
     * @throws OnlineCourseMaterialRefused
     */
    public function replace(OnlineCourseMaterial $material, UploadedFile|StagedUpload|FileLibraryNode $file): void
    {
        $this->assertAccepted($material->getKind(), $file);

        // The revision that was already waiting behind the live one goes: two are kept, never three.
        $stale = $material->getOther();
        $this->writeRevision($material, $file);
        if (null !== $stale) {
            $this->discard($material, $stale);
        }

        $material->getCourse()->touch();
    }

    /** Serves the kept revision again. False when there is none to go back to. */
    public function switchToOtherRevision(OnlineCourseMaterial $material): bool
    {
        if (!$material->switchToOther()) {
            return false;
        }

        $material->getCourse()->touch();

        return true;
    }

    public function remove(OnlineCourseMaterial $material): void
    {
        foreach ($material->getRevisions()->toArray() as $revision) {
            $this->discard($material, $revision);
        }

        $course = $material->getCourse();
        $course->removeMaterial($material);
        $course->touch();
        $this->entityManager->remove($material);
    }

    /** Hands every object of a course to the deferred purge - the course is about to be deleted. */
    public function removeAll(OnlineCourse $course): void
    {
        foreach ($course->getMaterials()->toArray() as $material) {
            $this->remove($material);
        }
    }

    /**
     * The order of the public page's tabs. Ids that are not this course's materials are ignored,
     * and a material left out keeps its place after the named ones.
     *
     * @param array<array-key, int> $orderedIds
     */
    public function reorder(OnlineCourse $course, array $orderedIds): void
    {
        $rank = array_flip(array_values($orderedIds));
        $materials = $course->getMaterials()->toArray();

        usort($materials, static fn (OnlineCourseMaterial $a, OnlineCourseMaterial $b): int => [$rank[$a->getId()] ?? \PHP_INT_MAX, $a->getPosition()] <=> [$rank[$b->getId()] ?? \PHP_INT_MAX, $b->getPosition()]);

        foreach ($materials as $position => $material) {
            $material->setPosition($position);
        }

        $course->touch();
    }

    private function writeRevision(OnlineCourseMaterial $material, UploadedFile|StagedUpload|FileLibraryNode $file): void
    {
        if ($material->getKind()->isBundle()) {
            throw new OnlineCourseMaterialRefused('onlineCourseMaterialWrongTypeMessage', ['%extensions%' => 'pdf, mp4']);
        }

        $number = $material->nextRevisionNumber();
        $prefix = $material->storagePrefixFor($number);
        $name = $this->storedName($file);

        if ($file instanceof FileLibraryNode) {
            $source = $file->getStorageKey() ?? throw new OnlineCourseMaterialRefused('onlineCourseMaterialNoFileMessage');
            $key = $prefix.$name;
            $this->fileUploads->copy($source, $key);
        } else {
            $key = $this->intake->store($file, $prefix, $name);
        }

        $material->publishRevision(new OnlineCourseMaterialRevision(
            $material,
            $number,
            $prefix,
            $key,
            UploadIntake::originalName($file),
            UploadIntake::size($file),
        ));
    }

    private function discard(OnlineCourseMaterial $material, OnlineCourseMaterialRevision $revision): void
    {
        foreach ($revision->storedKeys() as $key) {
            $this->fileUploads->delete($key);
        }

        $material->removeRevision($revision);
        $this->entityManager->remove($revision);
    }

    /**
     * The field already checked a file sent from a screen; this is for the doors that have no field
     * - a library file named by the Claude connector, which may be anything the library holds.
     */
    private function assertAccepted(OnlineCourseMaterialKind $kind, UploadedFile|StagedUpload|FileLibraryNode $file): void
    {
        $name = UploadIntake::originalName($file);
        $policy = $kind->uploadPolicy();
        $mimeType = UploadIntake::mimeType($file);

        if (null !== $policy->refusalReason($name, '' === $mimeType ? null : $mimeType)) {
            throw new OnlineCourseMaterialRefused('onlineCourseMaterialWrongTypeMessage', ['%extensions%' => implode(', ', $policy->extensions())]);
        }

        if (UploadIntake::size($file) > $policy->maxSizeInBytes()) {
            throw new OnlineCourseMaterialRefused('onlineCourseMaterialTooLargeMessage', ['%max%' => $policy->maxSize()]);
        }
    }

    /**
     * The last segment of the file's CDN address: readable, since it is what a browser proposes
     * when the reader saves a PDF, and never trusted - the folder is what makes the key unique.
     */
    private function storedName(UploadedFile|StagedUpload|FileLibraryNode $file): string
    {
        $original = UploadIntake::originalName($file);
        $extension = mb_strtolower(pathinfo($original, \PATHINFO_EXTENSION));
        $base = mb_substr($this->slug->from(pathinfo($original, \PATHINFO_FILENAME)), 0, 80);

        return ('' === $base ? 'support' : $base).('' === $extension ? '' : '.'.$extension);
    }

    private function uniqueSlug(OnlineCourse $course, OnlineCourseMaterialKind $kind): string
    {
        $slug = $kind->value;
        for ($suffix = 2; null !== $course->findMaterialBySlug($slug); ++$suffix) {
            $slug = $kind->value.'-'.$suffix;
        }

        return $slug;
    }

    private function nextPosition(OnlineCourse $course): int
    {
        $position = -1;
        foreach ($course->getMaterials() as $material) {
            $position = max($position, $material->getPosition());
        }

        return $position + 1;
    }
}
