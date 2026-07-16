<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converts legacy plain-text content (bare "\n" line breaks, no HTML at all) into equivalent
 * HugeRTE-shaped HTML (<p>/<br>) on the 4 InternshipProgramInfo/InternshipOptionExamModality
 * text fields backed by a HugeRTE editor (Mod. Examen + Mod. Contrat tabs, see
 * ProgramInternshipController::examModalitiesTab()/contractModalitiesTab()/
 * updateOptionExamModalities()).
 *
 * These fields predate being wired up to HugeRTE - their existing content was entered as plain
 * text, so the stored line breaks were never real block-level HTML. Rendering that text through
 * a rich editor (or the booklet PDF's `|raw` output) collapses every line break under normal
 * HTML whitespace rules, making multi-paragraph content look like one flowing blob. Fresh saves
 * through the editor already produce correct HTML (verified live) - only this backlog of
 * pre-HugeRTE content needs a one-time conversion. Rows that already contain a "<" are left
 * untouched (already real HTML, converting them again would double-escape).
 */
final class Version20260716050049 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Convert legacy plain-text InternshipProgramInfo/InternshipOptionExamModality content to HTML paragraphs/line breaks';
    }

    public function up(Schema $schema): void
    {
        $this->convertColumn('internship_program_info', 'exam_modality_text');
        $this->convertColumn('internship_program_info', 'terms_conditions_pro_text');
        $this->convertColumn('internship_program_info', 'terms_conditions_apprentissage_text');
        $this->convertColumn('internship_option_exam_modality', 'exam_modality_text');
    }

    public function down(Schema $schema): void
    {
        // Lossy by nature - the paragraph-vs-single-line-break distinction from the converted
        // HTML can't be losslessly mapped back onto the original plain text.
        $this->throwIrreversibleMigrationException();
    }

    private function convertColumn(string $table, string $column): void
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT id, %s AS value FROM %s WHERE %s IS NOT NULL AND %s <> \'\' AND %s NOT LIKE \'%%<%%\'', $column, $table, $column, $column, $column),
        );

        foreach ($rows as $row) {
            $this->addSql(
                sprintf('UPDATE %s SET %s = ? WHERE id = ?', $table, $column),
                [self::plainTextToHtml($row['value']), $row['id']],
            );
        }
    }

    private static function plainTextToHtml(string $text): string
    {
        $paragraphs = preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $text)));

        return implode("\n", array_map(
            static fn (string $paragraph): string => '<p>'.str_replace("\n", '<br>', htmlspecialchars($paragraph, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')).'</p>',
            $paragraphs,
        ));
    }
}
