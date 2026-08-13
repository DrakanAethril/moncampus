<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SeancePhaseTemplate;
use App\Entity\SeanceTemplate;
use App\Entity\SequenceTemplate;
use App\Enum\QuizSourceScope;
use App\Util\MarkdownRenderer;

/**
 * Reads the library into the four primitives App\Service\QuizSourceContext needs.
 *
 * What it decides is what "the course" means for a prompt, and every answer is a subtraction:
 *
 * - **The scope's own objectives**, not its nine text fields. A prompt carrying prerequisites,
 *   cross-curricular links and the problem situation asks for questions about the syllabus rather
 *   than about the lesson - and spends the character budget doing it.
 * - **The phases' content**, each named and timed. "Accueil et problématisation (20 min)" is what
 *   makes a question about that moment proportionate to it; a phase with nothing written contributes
 *   its name alone rather than a heading over a hole.
 * - **The cahier de texte is not in it at all.** It is the trace left for students after the fact,
 *   the one HTML field of the three entities, and not what the séance teaches.
 *
 * No service dependency and no repository: it walks the collections it is handed, which is what makes
 * it testable on entities built in memory.
 */
final class QuizSourceContextFactory
{
    public function forSequence(SequenceTemplate $sequence): QuizSourceContext
    {
        $phases = [];
        foreach ($sequence->getSeanceTemplates() as $seance) {
            $lines = $this->phaseLines($seance);
            if ([] === $lines) {
                continue;
            }
            // Each séance names itself: a question has to be able to belong to the right moment of
            // the course, and "phase 3" means nothing across four séances.
            $phases[] = implode("\n", [$this->seanceHeading($seance), ...$lines]);
        }

        return new QuizSourceContext(
            QuizSourceScope::Sequence,
            (string) $sequence->getTitre(),
            $this->plain($sequence->getObjectifs()),
            implode("\n\n", $phases),
        );
    }

    public function forSeance(SeanceTemplate $seance): QuizSourceContext
    {
        return new QuizSourceContext(
            QuizSourceScope::Seance,
            (string) $seance->getTitre(),
            $this->plain($seance->getObjectifs()),
            implode("\n", $this->phaseLines($seance)),
        );
    }

    /** @return list<string> one line per phase, in the déroulé's own order */
    private function phaseLines(SeanceTemplate $seance): array
    {
        $lines = [];
        foreach ($seance->getSeancePhaseTemplates() as $phase) {
            $name = trim((string) $phase->getNom());
            if ('' === $name) {
                continue;
            }

            $line = '- '.$name.$this->duration($phase);
            $contenu = $this->plain($phase->getContenu());
            // No dangling ": " when the phase carries no content - the name is the whole line.
            $lines[] = '' === $contenu ? $line : $line.' : '.$contenu;
        }

        return $lines;
    }

    private function seanceHeading(SeanceTemplate $seance): string
    {
        return trim((string) $seance->getTitre()).$this->minutes($seance->getDuree());
    }

    private function duration(SeancePhaseTemplate $phase): string
    {
        return $this->minutes($phase->getDuree());
    }

    /**
     * A séance/phase duration is a DECIMAL(10,2) of MINUTES - never hours, whatever the timetable's
     * LessonSession does with the same word (CLAUDE.md's duration gotcha). Written out as minutes here
     * rather than formatted, because the prompt is read by a model and "75 min" is unambiguous where
     * "1 h 15" invites arithmetic.
     */
    private function minutes(?string $raw): string
    {
        if (null === $raw || '' === trim($raw)) {
            return '';
        }

        $minutes = (int) round((float) $raw);

        return 0 === $minutes ? '' : \sprintf(' (%d min)', $minutes);
    }

    /**
     * Plain text, always. These fields are stored as plain text and rendered escaped, but a Markdown
     * table or a stray tag that reached one would otherwise be characters the teacher pays for and the
     * model has to skip - and the counter on screen must count what the model reads.
     */
    private function plain(?string $raw): string
    {
        if (null === $raw || '' === trim($raw)) {
            return '';
        }

        return MarkdownRenderer::toPlainText(strip_tags($raw));
    }
}
