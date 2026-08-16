<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wiki;
use App\Entity\WikiNode;
use App\Repository\WikiNodeRepository;

/**
 * Writes a wiki out as the Markdown archive described in design/validated/wiki.md.
 *
 *     wiki-<slug>/
 *       manifest.json
 *       pages/01-introduction.md
 *       pages/01-introduction.html
 *       pages/02-reseaux/index.md
 *       pages/02-reseaux/index.html
 *       attachments/48/schema.pdf
 *
 * A folder becomes a directory holding `index.md`/`index.html`, which is what makes the tree
 * legible when the archive is simply unzipped - the alternative, a flat list with the hierarchy
 * only in the manifest, reads as a pile of files to everything except this application.
 *
 * `manifest.json`'s `format` token follows the existing `moncampus-quiz/1` convention, and its
 * `html` entries are what a re-import reads: the `.md` is the portable artefact, the `.html` is the
 * authoritative one.
 */
class WikiArchiveExporter
{
    public const string FORMAT = 'moncampus-wiki/1';

    public function __construct(
        private readonly WikiNodeRepository $nodes,
        private readonly WikiTree $tree,
        private readonly WikiMarkdown $markdown,
        private readonly FileUploadService $uploads,
        private readonly HelpSlug $slug,
    ) {
    }

    /**
     * @return string the path of the written archive, which the caller must send and then delete
     */
    public function export(Wiki $wiki, \DateTimeImmutable $exportedAt): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wiki-archive');

        if (false === $path) {
            throw new \RuntimeException('Could not create a temporary file for the archive.');
        }

        $zip = new \ZipArchive();

        if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Could not open the archive for writing.');
        }

        $root = 'wiki-'.($this->slug->from($wiki->getTitle()) ?: 'export');
        $manifest = [
            'format' => self::FORMAT,
            'exportedAt' => $exportedAt->format(\DateTimeInterface::ATOM),
            'wiki' => ['title' => $wiki->getTitle(), 'type' => $wiki->getType()->value],
            'nodes' => [],
        ];

        foreach ($this->ordered($wiki) as $entry) {
            $manifest['nodes'][] = $this->writeNode($zip, $root, $entry['node'], $entry['path']);
        }

        $zip->addFromString($root.'/manifest.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) ?: '{}');
        $zip->close();

        return $path;
    }

    /**
     * @param non-empty-string $root
     *
     * @return array<string, mixed>
     */
    private function writeNode(\ZipArchive $zip, string $root, WikiNode $node, string $relativePath): array
    {
        $html = $node->getBody() ?? '';
        $front = $this->markdown->frontMatter([
            'title' => $node->getTitle(),
            'slug' => $node->getSlug(),
            'position' => $node->getPosition(),
            'createdAt' => $node->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $node->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);

        $zip->addFromString($root.'/'.$relativePath.'.md', $front.$this->markdown->fromHtml($html));
        $zip->addFromString($root.'/'.$relativePath.'.html', $html);

        $attachments = [];

        foreach ($node->getAttachments() as $attachment) {
            $entryPath = \sprintf('attachments/%d/%s', $node->getId(), $attachment->getLabel());

            try {
                $zip->addFromString($root.'/'.$entryPath, $this->uploads->read($attachment->getStorageKey()));
            } catch (\Throwable) {
                // A file that has vanished from the bucket must not take the whole export down:
                // the manifest simply does not name it, and the rest of the wiki still leaves.
                continue;
            }

            $attachments[] = [
                'label' => $attachment->getLabel(),
                'path' => $entryPath,
                'mime' => $attachment->getMimeType(),
                'size' => $attachment->getSizeBytes(),
            ];
        }

        return [
            'id' => $node->getId(),
            'parentId' => $node->getParent()?->getId(),
            'type' => $node->getType()->value,
            'position' => $node->getPosition(),
            'title' => $node->getTitle(),
            'slug' => $node->getSlug(),
            'markdown' => $relativePath.'.md',
            'html' => $relativePath.'.html',
            'attachments' => $attachments,
        ];
    }

    /**
     * Depth-first, each node with the path it is written at - a node with children becomes a
     * directory and lives in its own `index`.
     *
     * @return list<array{node: WikiNode, path: string}>
     */
    private function ordered(Wiki $wiki): array
    {
        $rows = [];

        foreach ($this->nodes->findLiveOf($wiki) as $node) {
            $id = $node->getId();

            if (null === $id) {
                continue;
            }

            $rows[] = ['id' => $id, 'parentId' => $node->getParent()?->getId(), 'position' => $node->getPosition(), 'node' => $node];
        }

        $entries = [];
        $this->walk($this->tree->assemble($rows), 'pages', $entries);

        return $entries;
    }

    /**
     * @param list<array<string, mixed>>                  $branch
     * @param list<array{node: WikiNode, path: string}>   $entries
     *
     * @param-out list<array{node: WikiNode, path: string}> $entries
     */
    private function walk(array $branch, string $prefix, array &$entries): void
    {
        $rank = 1;

        foreach ($branch as $row) {
            /** @var WikiNode $node */
            $node = $row['node'];
            /** @var list<array<string, mixed>> $children */
            $children = $row['children'];

            // The numeric prefix is what keeps the reading order visible in a plain file manager,
            // which is the only ordering an unzipped archive can carry.
            $name = \sprintf('%02d-%s', $rank++, $node->getSlug());

            if ([] === $children) {
                $entries[] = ['node' => $node, 'path' => $prefix.'/'.$name];

                continue;
            }

            $entries[] = ['node' => $node, 'path' => $prefix.'/'.$name.'/index'];
            $this->walk($children, $prefix.'/'.$name, $entries);
        }
    }
}
