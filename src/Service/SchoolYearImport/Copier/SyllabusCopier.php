<?php

declare(strict_types=1);

namespace App\Service\SchoolYearImport\Copier;

use App\Enum\SchoolYearImportBlock;
use App\Service\FileUploadService;
use App\Service\ProgramPdfReplacer;
use App\Service\SchoolYearImport\ImportRun;
use App\Service\SchoolYearImport\ProgramPair;
use League\Flysystem\FilesystemException;

/**
 * « Programme de la formation »: its mode and visibility for a formation created, and its PDF
 * **copied** under a new key (R7) - never referenced, or the deferred purge of the original would
 * take it along. A formation already there receives the PDF only when it has none, and keeps its
 * mode. The keys written are remembered, so that an import rolled back hands them to the purge.
 */
final class SyllabusCopier implements ProgramCopier
{
    /** @var list<string> */
    private array $written = [];

    public function __construct(private readonly FileUploadService $files)
    {
    }

    public function copy(ImportRun $run, ProgramPair $pair): void
    {
        if (!$run->has(SchoolYearImportBlock::Syllabus)) {
            return;
        }

        $source = $pair->source;
        $write = $run->writable($pair);
        if ($pair->created) {
            $write?->setSyllabusMode($source->getSyllabusMode());
            $write?->setSyllabusVisibility($source->getSyllabusVisibility());
        }

        $key = $source->getSyllabusFileKey();
        if (null === $key || '' === $key || null !== $pair->target?->getSyllabusFileKey()) {
            return;
        }
        $run->report->add($pair, 'syllabus');
        if (null === $write) {
            return;
        }

        $extension = pathinfo($key, \PATHINFO_EXTENSION);
        $newKey = ProgramPdfReplacer::SYLLABUS_PREFIX.'import-'.bin2hex(random_bytes(8)).('' !== $extension ? '.'.$extension : '');
        try {
            $this->files->copy($key, $newKey);
        } catch (FilesystemException) {
            $run->report->note($pair, 'schoolYearImportNoteSyllabusMissing');

            return;
        }
        $this->written[] = $newKey;
        $write->setSyllabusFileKey($newKey);
    }

    /** @return list<string> the keys copied since the last call, which forgets them */
    public function takeWritten(): array
    {
        $written = $this->written;
        $this->written = [];

        return $written;
    }
}
