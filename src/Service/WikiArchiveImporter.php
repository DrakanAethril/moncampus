<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Wiki;
use App\Entity\WikiAttachment;
use App\Entity\WikiNode;
use App\Enum\WikiNodeType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Reads an archive into a wiki - the dry run first, then the same reading applied.
 *
 * **The analysis and the import walk the archive the same way on purpose.** A dry run that
 * describes one thing and an import that does another is worse than no dry run at all, so
 * `analyse()` and `import()` share pages() and differ only in whether they write.
 *
 * Two things the dry run gets right, both already paid for once in this repository:
 *
 *  - **a dry run that never flushes does not see NOT NULL constraints.** So the analysis validates
 *    against the entity's own rules explicitly - a page with no title is reported *here*, not
 *    discovered by the database halfway through the import;
 *  - **archive safety** is App\Service\WikiArchiveReader's, applied before anything is read.
 *
 * Every extracted file crosses App\Service\FileUploadService, so it is scanned by the same
 * antivirus as a hand-picked upload - which is what finally closes the hole the extension allowlist
 * cannot reach: a `.zip` full of malware is caught on the way in, not merely stored intact.
 */
class WikiArchiveImporter
{
    private const string ATTACHMENT_PREFIX = 'wiki/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WikiArchiveReader $reader,
        private readonly WikiMarkdown $markdown,
        private readonly WikiNodeManager $nodeManager,
        private readonly FileUploadService $uploads,
    ) {
    }

    public function analyse(string $path): WikiArchiveAnalysis
    {
        try {
            $archive = $this->reader->open($path);
        } catch (WikiArchiveException $refused) {
            $analysis = new WikiArchiveAnalysis();
            $analysis->block($refused->messageKey);

            return $analysis;
        }

        try {
            return $this->describe($archive);
        } finally {
            $archive['zip']->close();
        }
    }

    /**
     * Applies what analyse() described. Refuses to start if the analysis blocks, so the two can
     * never disagree about whether the file was importable.
     *
     * @return int how many pages were created
     */
    public function import(
        string $path,
        Wiki $wiki,
        ?WikiNode $parent,
        User $author,
        HtmlSanitizerInterface $sanitizer,
    ): int {
        $archive = $this->reader->open($path);

        try {
            $pages = $this->pages($archive);
            $created = 0;
            /** @var array<string, WikiNode> $byPath */
            $byPath = [];

            foreach ($pages as $page) {
                $under = null === $page['parentPath'] ? $parent : ($byPath[$page['parentPath']] ?? $parent);
                $node = $this->nodeManager->create($wiki, $under, WikiNodeType::Page, $page['title'], $author);
                $this->entityManager->flush();

                $this->nodeManager->writeBody($node, $sanitizer->sanitize($page['html']), $author);
                $byPath[$page['path']] = $node;
                ++$created;

                foreach ($page['attachments'] as $attachment) {
                    $this->attach($archive, $node, $attachment);
                }

                $this->entityManager->flush();
            }

            return $created;
        } finally {
            $archive['zip']->close();
        }
    }

    /**
     * @param array{zip: \ZipArchive, root: string, manifest: ?array<string, mixed>, entries: list<array{name: string, size: int}>} $archive
     */
    private function describe(array $archive): WikiArchiveAnalysis
    {
        $analysis = new WikiArchiveAnalysis(
            null !== $archive['manifest'] ? WikiArchiveAnalysis::KIND_MONCAMPUS : WikiArchiveAnalysis::KIND_GENERIC,
        );

        foreach ($this->pages($archive) as $page) {
            // Validated against the entity's own rules rather than trusted to import: a title is
            // NOT NULL and capped at 255, and a dry run that never flushes would not find that out.
            if ('' === trim($page['title'])) {
                $analysis->warn('wikiImportUntitledPageWarning', $page['path']);

                continue;
            }

            $analysis->pages[] = [
                'title' => mb_substr($page['title'], 0, 255),
                'path' => $page['path'],
                'depth' => $page['depth'],
                'attachments' => \count($page['attachments']),
            ];
            $analysis->attachments += \count($page['attachments']);
        }

        if ([] === $analysis->pages) {
            $analysis->block('wikiImportNoPageMessage');
        }

        return $analysis;
    }

    /**
     * The archive's pages, in order, however it is shaped - one reading for the dry run and for the
     * import both.
     *
     * @param array{zip: \ZipArchive, root: string, manifest: ?array<string, mixed>, entries: list<array{name: string, size: int}>} $archive
     *
     * @return list<array{title: string, html: string, path: string, parentPath: ?string, depth: int, attachments: list<array{label: string, path: string}>}>
     */
    private function pages(array $archive): array
    {
        return null !== $archive['manifest']
            ? $this->pagesFromManifest($archive)
            : $this->pagesFromTree($archive);
    }

    /**
     * @param array{zip: \ZipArchive, root: string, manifest: ?array<string, mixed>, entries: list<array{name: string, size: int}>} $archive
     *
     * @return list<array{title: string, html: string, path: string, parentPath: ?string, depth: int, attachments: list<array{label: string, path: string}>}>
     */
    private function pagesFromManifest(array $archive): array
    {
        /** @var array<string, mixed> $manifest */
        $manifest = $archive['manifest'];
        $nodes = $manifest['nodes'] ?? [];

        if (!\is_array($nodes)) {
            return [];
        }

        // The manifest's ids are the exporting wiki's, which say nothing here - they are only used
        // to rebuild the parent relation among the rows being imported.
        $paths = [];
        $depths = [];
        $pages = [];

        foreach ($nodes as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $id = \is_int($row['id'] ?? null) ? $row['id'] : null;
            $parentId = \is_int($row['parentId'] ?? null) ? $row['parentId'] : null;

            if (null === $id) {
                continue;
            }

            $htmlEntry = \is_string($row['html'] ?? null) ? $row['html'] : null;
            $path = 'node-'.$id;
            $paths[$id] = $path;
            $depths[$id] = null !== $parentId && isset($depths[$parentId]) ? $depths[$parentId] + 1 : 0;

            $attachments = [];

            foreach (\is_array($row['attachments'] ?? null) ? $row['attachments'] : [] as $attachment) {
                if (\is_array($attachment) && \is_string($attachment['path'] ?? null) && \is_string($attachment['label'] ?? null)) {
                    $attachments[] = ['label' => $attachment['label'], 'path' => $attachment['path']];
                }
            }

            $pages[] = [
                'title' => \is_string($row['title'] ?? null) ? $row['title'] : '',
                // The .html is authoritative on re-import - that is the whole reason it is written.
                'html' => null !== $htmlEntry ? $this->reader->read($archive['zip'], $archive['root'].$htmlEntry) : '',
                'path' => $path,
                'parentPath' => null !== $parentId ? ($paths[$parentId] ?? null) : null,
                'depth' => $depths[$id],
                'attachments' => $attachments,
            ];
        }

        return $pages;
    }

    /**
     * A generic Markdown tree: the directory structure becomes the node tree, the title comes from
     * the first H1 or from the filename.
     *
     * @param array{zip: \ZipArchive, root: string, manifest: ?array<string, mixed>, entries: list<array{name: string, size: int}>} $archive
     *
     * @return list<array{title: string, html: string, path: string, parentPath: ?string, depth: int, attachments: list<array{label: string, path: string}>}>
     */
    private function pagesFromTree(array $archive): array
    {
        $files = [];

        foreach ($archive['entries'] as $entry) {
            $relative = str_starts_with($entry['name'], $archive['root'])
                ? substr($entry['name'], \strlen($archive['root']))
                : $entry['name'];

            if (\in_array(mb_strtolower(pathinfo($relative, \PATHINFO_EXTENSION)), ['md', 'markdown'], true)) {
                $files[] = $relative;
            }
        }

        // Alphabetical, which for an exported tree is the order its numeric prefixes were written
        // to produce.
        sort($files);
        $pages = [];

        foreach ($files as $relative) {
            $directory = \dirname($relative);
            $isIndex = 'index' === pathinfo($relative, \PATHINFO_FILENAME);
            // An "index.md" IS its directory; anything else hangs under it.
            $path = $isIndex ? $directory : $relative;
            $parent = $isIndex ? \dirname($directory) : $directory;
            $source = $this->markdown->withoutFrontMatter($this->reader->read($archive['zip'], $archive['root'].$relative));

            $pages[] = [
                'title' => $this->markdown->titleOf($source, basename($relative)),
                'html' => $this->markdown->toHtml($source),
                'path' => $path,
                'parentPath' => '.' === $parent || '' === $parent ? null : $parent,
                'depth' => '.' === $parent || '' === $parent ? 0 : substr_count($parent, '/') + 1,
                // A generic tree names its images inline; resolving those is reported rather than
                // guessed - see the warning the analysis raises.
                'attachments' => [],
            ];
        }

        return $pages;
    }

    /**
     * @param array{zip: \ZipArchive, root: string, manifest: ?array<string, mixed>, entries: list<array{name: string, size: int}>} $archive
     * @param array{label: string, path: string}                                                                                   $attachment
     */
    private function attach(array $archive, WikiNode $node, array $attachment): void
    {
        $bytes = $this->reader->read($archive['zip'], $archive['root'].$attachment['path']);

        if ('' === $bytes) {
            return;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'wiki-attach');

        if (false === $temporary) {
            return;
        }

        file_put_contents($temporary, $bytes);

        try {
            // Through the ordinary upload path, so the extracted file is scanned exactly like a
            // hand-picked one - the point of routing it here rather than writing to S3 directly.
            $upload = new UploadedFile($temporary, $attachment['label'], null, null, true);
            $extension = pathinfo(mb_strtolower($attachment['label']), \PATHINFO_EXTENSION);

            if (!UploadPolicy::platform()->accepts($attachment['label'], $upload->getMimeType())) {
                return;
            }

            $key = $this->uploads->upload(
                self::ATTACHMENT_PREFIX,
                \sprintf('%s.%s', bin2hex(random_bytes(16)), $extension),
                $upload,
            );

            $node->addAttachment(
                (new WikiAttachment($attachment['label'], $key))
                    ->setSizeBytes(\strlen($bytes))
                    ->setPosition(\count($node->getAttachments())),
            );
        } catch (\Throwable) {
            // An attachment that is refused - hostile, or a kind the platform does not accept -
            // must not take the page it belongs to down with it.
        } finally {
            @unlink($temporary);
        }
    }
}
