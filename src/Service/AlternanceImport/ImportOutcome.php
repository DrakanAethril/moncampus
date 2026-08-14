<?php

declare(strict_types=1);

namespace App\Service\AlternanceImport;

/**
 * What the import actually wrote - the recap screen's whole content.
 *
 * Counted from the writes themselves rather than copied from the analysis: the two agree in the
 * normal case, and when they don't, the operator is shown what happened, not what was predicted.
 */
final readonly class ImportOutcome
{
    /**
     * @param list<string> $createdEnterpriseNames
     * @param list<string> $createdTutorLabels     "Prénom NOM <mail>", one per provisioned account
     * @param list<string> $taggedStudentLabels    students who gained the alternance modality
     * @param list<string> $skippedStudentLabels   already held the same alternance
     */
    public function __construct(
        public int $createdAlternances,
        public array $createdEnterpriseNames,
        public array $createdTutorLabels,
        public array $taggedStudentLabels,
        public array $skippedStudentLabels,
    ) {
    }
}
