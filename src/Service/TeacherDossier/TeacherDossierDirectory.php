<?php

declare(strict_types=1);

namespace App\Service\TeacherDossier;

use App\Enum\DossierDocumentStatus;
use App\Enum\TeacherDossierFilter;
use App\Repository\TeacherDossierRepository;

/**
 * The administration's list of dossiers intervenants: every dossier read once, then counted and
 * filtered in memory.
 *
 * Two queries whatever the filter - the dossiers with their pieces, and every dépôt of them - and
 * the figures of the filter bar are counted on the same sheets the rows are drawn from, so a count
 * and the list under it cannot disagree.
 *
 * « Incomplet », « complet » and « à valider » are said of **published** dossiers only: a draft has
 * not been handed to anybody and a closed one is no longer asking - each has its own filter.
 */
class TeacherDossierDirectory
{
    public function __construct(
        private readonly TeacherDossierRepository $dossiers,
        private readonly TeacherDossierSheets $sheets,
    ) {
    }

    /**
     * @return array{
     *     rows: list<TeacherDossierSheet>,
     *     counts: array<string, int>,
     *     kpis: array{incomplete: int, toReview: int, late: int},
     * }
     */
    public function read(TeacherDossierFilter $filter, string $search = '', ?\DateTimeImmutable $today = null): array
    {
        $sheets = $this->sheets->ofAll($this->dossiers->findDossiers(), $today);
        $counts = [];

        foreach (TeacherDossierFilter::cases() as $case) {
            $counts[$case->value] = \count(array_filter($sheets, fn (TeacherDossierSheet $sheet): bool => self::matches($case, $sheet)));
        }

        $toReview = 0;
        $late = 0;

        foreach ($sheets as $sheet) {
            if ($sheet->dossier->isPublished()) {
                $toReview += $sheet->countWithStatus(DossierDocumentStatus::AwaitingReview);
                $late += $sheet->countWithStatus(DossierDocumentStatus::Late);
            }
        }

        $needle = mb_strtolower(trim($search));

        return [
            'rows' => array_values(array_filter(
                $sheets,
                static fn (TeacherDossierSheet $sheet): bool => self::matches($filter, $sheet) && self::names($sheet, $needle),
            )),
            'counts' => $counts,
            'kpis' => ['incomplete' => $counts[TeacherDossierFilter::Incomplete->value], 'toReview' => $toReview, 'late' => $late],
        ];
    }

    public static function matches(TeacherDossierFilter $filter, TeacherDossierSheet $sheet): bool
    {
        $dossier = $sheet->dossier;

        return match ($filter) {
            TeacherDossierFilter::Incomplete => $dossier->isPublished() && !$sheet->isComplete(),
            TeacherDossierFilter::ToReview => $dossier->isPublished() && $sheet->countWithStatus(DossierDocumentStatus::AwaitingReview) > 0,
            TeacherDossierFilter::Complete => $dossier->isPublished() && $sheet->isComplete(),
            TeacherDossierFilter::Draft => $dossier->isDraft(),
            TeacherDossierFilter::Closed => $dossier->isClosed(),
            TeacherDossierFilter::All => true,
        };
    }

    /** Does the search name this row - by its intervenant or by its title? An empty search names all. */
    private static function names(TeacherDossierSheet $sheet, string $needle): bool
    {
        if ('' === $needle) {
            return true;
        }

        $teacher = $sheet->dossier->getTeacher();
        $haystack = mb_strtolower(($teacher?->getDisplayName() ?? '').' '.($teacher?->getUsername() ?? '').' '.$sheet->dossier->getTitle());

        return str_contains($haystack, $needle);
    }
}
