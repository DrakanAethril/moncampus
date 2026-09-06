<?php

declare(strict_types=1);

namespace App\Service\WordCloud;

use App\Entity\User;
use App\Entity\WordCloud;
use App\Entity\WordCloudSubmission;
use App\Enum\WordCloudModerationState;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The nominative history of a cloud, as a CSV: one line per word written, plus one line per student
 * of the audience who wrote nothing.
 *
 * Those last lines are why the file is worth having. A file built from the submissions alone
 * exports the people who took part, and « qui n'a rien proposé » - the question both follow-up
 * screens are built around - is then the one thing it cannot answer. The word columns stay empty on
 * them, and the état column says so.
 *
 * Semicolon-separated and opening on a UTF-8 BOM, like every other export of this repository: it is
 * opened in Excel, in French, and without either the accents break and the columns land in one cell.
 */
class WordCloudCsvExporter
{
    /**
     * @param list<User>                $roster      the cloud's audience
     * @param list<WordCloudSubmission> $submissions every row of the cloud, chronological
     */
    public function export(WordCloud $cloud, array $roster, array $submissions, WordCloudFollowUp $followUp): string
    {
        $rows = [['Étudiant', 'Mot', 'Horodatage', 'État']];

        foreach ($submissions as $submission) {
            $rows[] = [
                $this->displayName($submission->getStudent()),
                (string) $submission->getText(),
                $submission->getSubmittedAt()->format('d/m/Y H:i'),
                $this->stateLabel($submission->getModerationState()),
            ];
        }

        foreach ($followUp->silentStudents($roster, $submissions) as $silent) {
            $rows[] = [$this->displayName($silent), '', '', 'Sans réponse'];
        }

        $csv = "\u{FEFF}";
        foreach ($rows as $row) {
            $csv .= implode(';', array_map($this->escape(...), $row))."\r\n";
        }

        return $csv;
    }

    /**
     * The same slug the PDF export uses, transliterated rather than stripped: « Cybersécurité »
     * becomes « cybersecurite » instead of « cybers-curit », and the two exports of one cloud land
     * in the download folder next to each other.
     */
    public function filename(WordCloud $cloud): string
    {
        $slug = (new AsciiSlugger())->slug((string) $cloud->getName())->lower()->toString();

        return ('' !== $slug ? $slug : 'nuage-de-mots').'.csv';
    }

    private function stateLabel(WordCloudModerationState $state): string
    {
        return match ($state) {
            WordCloudModerationState::Pending => 'En attente',
            WordCloudModerationState::Approved => 'Retenu',
            WordCloudModerationState::Rejected => 'Refusé',
        };
    }

    private function displayName(?User $user): string
    {
        if (null === $user) {
            return '';
        }

        return $user->getDisplayName() ?? $user->getUsername();
    }

    private function escape(string $value): string
    {
        return '"'.str_replace('"', '""', str_replace(["\r\n", "\n", "\r"], ' ', $value)).'"';
    }
}
