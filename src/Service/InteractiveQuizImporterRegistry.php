<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The families of the "Import interactif (JSON)" screen, in the order their tabs are shown.
 *
 * Autowired from every App\Service\InteractiveQuizImporter, so adding a fourth family is one new
 * class and nothing else - the screen, the dispatch and the preview all follow from this.
 */
final class InteractiveQuizImporterRegistry
{
    /** @var list<InteractiveQuizImporter> */
    private readonly array $importers;

    /** @param iterable<InteractiveQuizImporter> $importers */
    public function __construct(iterable $importers)
    {
        $this->importers = array_values([...$importers]);
    }

    /** @return list<InteractiveQuizImporter> */
    public function all(): array
    {
        return $this->importers;
    }

    /** The importer for a `?family=` value, falling back to the first tab for anything unknown. */
    public function forFamily(?string $family): InteractiveQuizImporter
    {
        foreach ($this->importers as $importer) {
            if ($importer->family() === $family) {
                return $importer;
            }
        }

        return $this->importers[0];
    }

    /**
     * Which importer should read this document. The pasted JSON names its own format, so a teacher
     * who opened one tab and pasted another family's document still gets what they meant; only a
     * document that names no known format falls back to the tab they are on, which is what makes
     * the "expected format" error name the format they were looking at.
     */
    public function forDocument(string $json, ?string $fallbackFamily): InteractiveQuizImporter
    {
        $document = json_decode($json, true);
        $tag = \is_array($document) && \is_scalar($document['format'] ?? null) ? (string) $document['format'] : null;

        foreach ($this->importers as $importer) {
            if ($importer->formatTag() === $tag) {
                return $importer;
            }
        }

        return $this->forFamily($fallbackFamily);
    }

    /**
     * The importer that produced a session payload, or null when it came from the CSV/Kahoot route -
     * which has no interactive family and its own screen.
     */
    public function forPayloadFormat(mixed $format): ?InteractiveQuizImporter
    {
        foreach ($this->importers as $importer) {
            if ($importer->payloadFormat() === $format) {
                return $importer;
            }
        }

        return null;
    }
}
