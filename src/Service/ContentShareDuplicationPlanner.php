<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a duplication will create in the recipient's file library, and what it will weigh -
 * design/validated/content-sharing-between-teachers.md, "Where the duplicated files go".
 *
 * The rule the request settles, and the one place this feature writes outside the library's own
 * rules:
 *
 *     <Titre de la séquence>/                          - the séquence-level supports
 *     <Titre de la séquence>/<Titre de la séance>/     - that séance's supports, and its phases'
 *
 * Three decisions inside it:
 *
 * - **a phase's supports go into its séance's folder**, not into a third level. A folder named
 *   « Accueil » or « Synthèse » per séance is noise, and the request stops at the séance;
 * - **a séance with nothing to file gets no folder**, and a séquence with nothing to file gets no
 *   folder at all - an empty folder named after the séquence helps nobody;
 * - **a link weighs nothing and creates no file.** It is a URL: the copy is the string.
 *
 * Pure, over primitives, and that is the point: the shape of the folders and above all **the sum of
 * the bytes** must be answerable without a séquence, a library or a bucket. That sum is the single
 * number the quota is asked about - asking per file is exactly how a partial write happens, and a
 * partial write looks like a success.
 *
 * @phpstan-type PlannedResource array{label: string, storageKey: string|null, bytes: int}
 * @phpstan-type PlannedSeance array{title: string, resources: list<PlannedResource>, phaseResources: list<PlannedResource>}
 * @phpstan-type PlannedFolder array{name: string, parentIndex: int|null, files: list<PlannedResource>}
 * @phpstan-type DuplicationPlan array{folders: list<PlannedFolder>, fileCount: int, linkCount: int, totalBytes: int}
 */
class ContentShareDuplicationPlanner
{
    /**
     * @param list<PlannedResource> $sequenceResources
     * @param list<PlannedSeance>   $seances
     *
     * @return DuplicationPlan
     */
    public function plan(string $sequenceTitle, array $sequenceResources, array $seances): array
    {
        $rootFiles = $this->uploadsOf($sequenceResources);
        $linkCount = \count($sequenceResources) - \count($rootFiles);

        $seanceFolders = [];
        $takenNames = [];
        $position = 0;

        foreach ($seances as $seance) {
            ++$position;
            $resources = array_merge($seance['resources'], $seance['phaseResources']);
            $files = $this->uploadsOf($resources);
            $linkCount += \count($resources) - \count($files);

            if ([] === $files) {
                continue;
            }

            // Two séances of the same name would collide inside the séquence folder. Named apart
            // here rather than left to the writer, so the confirmation screen shows the folders that
            // will really be created - FileLibraryTree::uniqueName() is the same authority the
            // library itself uses, and it will simply agree at write time.
            $name = $this->folderName($seance['title'], $position, $takenNames);
            $takenNames[] = $name;
            $seanceFolders[] = ['name' => $name, 'parentIndex' => 0, 'files' => $files];
        }

        $fileCount = \count($rootFiles);
        $totalBytes = $this->sum($rootFiles);

        foreach ($seanceFolders as $folder) {
            $fileCount += \count($folder['files']);
            $totalBytes += $this->sum($folder['files']);
        }

        // Nothing to file: no folders at all, and the confirmation screen says so instead of drawing
        // an empty tree.
        if (0 === $fileCount) {
            return ['folders' => [], 'fileCount' => 0, 'linkCount' => $linkCount, 'totalBytes' => 0];
        }

        return [
            'folders' => array_merge([['name' => $sequenceTitle, 'parentIndex' => null, 'files' => $rootFiles]], $seanceFolders),
            'fileCount' => $fileCount,
            'linkCount' => $linkCount,
            'totalBytes' => $totalBytes,
        ];
    }

    /**
     * @param list<PlannedResource> $resources
     *
     * @return list<PlannedResource>
     */
    private function uploadsOf(array $resources): array
    {
        return array_values(array_filter($resources, static fn (array $resource): bool => null !== $resource['storageKey']));
    }

    /** @param list<PlannedResource> $files */
    private function sum(array $files): int
    {
        return array_sum(array_column($files, 'bytes'));
    }

    /** @param list<string> $takenNames */
    private function folderName(string $title, int $position, array $takenNames): string
    {
        $title = trim($title);
        // A séance with no title of its own still needs a folder somebody can find tomorrow.
        $title = '' === $title ? \sprintf('Séance %d', $position) : $title;

        if (!\in_array($title, $takenNames, true)) {
            return $title;
        }

        $suffix = 2;

        while (\in_array(\sprintf('%s (%d)', $title, $suffix), $takenNames, true)) {
            ++$suffix;
        }

        return \sprintf('%s (%d)', $title, $suffix);
    }
}
