<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the badge on a cahier de texte says: whether the lesson was written up, started, or not
 * touched at all.
 *
 * Named after App\Service\StudentWorkBoard and for the same reason - the state rule of a screen
 * belongs in one place, not spread through the controller that renders it. Every method takes plain
 * values rather than entities: the rule only ever looks at the three texts.
 *
 * It used to choose the week too, and to jump forward when the current one had no lesson. That went
 * with the class-scoped course view it served: the screen that replaced it answers « the current
 * week » and lets an empty week be an answer - see App\Service\LessonLogPeriodBoard.
 */
final class LessonLogBoard
{
    /**
     * The badge on one row, in a word.
     *
     * Only what was actually taught decides. The lesson log is the record of the lesson, and that is
     * the one section nothing else can stand in for; the before/after sections carry the work given,
     * which may legitimately be empty for a session - counting them would mark a perfectly kept log
     * as incomplete. Hence two states and no "partial".
     *
     * The content is HugeRTE's HTML, and an "emptied" paragraph is not an empty string there: the
     * editor leaves `<p>&nbsp;</p>` behind when a teacher types something and deletes it. Entities
     * are therefore decoded before the emptiness test, and the whitespace that test ignores has to
     * include the non-breaking space itself - which trim() does not touch, being multi-byte.
     *
     * @param ?string $duringContent the "pendant" section's HTML, null when there is no log at all
     */
    public function stateOf(?string $duringContent): string
    {
        $text = html_entity_decode(strip_tags((string) $duringContent), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // \x{00A0} non-breaking space, \x{200B} zero-width space, \x{FEFF} byte-order mark - the
        // three invisibles a paste from Word or a browser can leave in an otherwise empty field.
        return '' === preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $text) ? 'empty' : 'filled';
    }

    /**
     * The tag on a row of the period screen, in one of three words.
     *
     * The two-state badge above stays the authority on « rempli »: only the account of the lesson
     * makes a log kept. What the third state adds is the difference between a séance nobody has
     * touched and one where the work was given but the lesson not yet written up - a teacher
     * scanning the week wants those two apart, and the older screen could only call both « vide ».
     *
     * @param bool $hasExtras whether a document or an assignment hangs off the séance
     */
    public function sessionStateOf(?string $before, ?string $during, ?string $after, bool $hasExtras): string
    {
        if ('filled' === $this->stateOf($during)) {
            return 'filled';
        }

        $started = $hasExtras
            || 'filled' === $this->stateOf($before)
            || 'filled' === $this->stateOf($after);

        return $started ? 'partial' : 'empty';
    }

    /**
     * The same three states read on one part of the cahier de texte - the dot of the preview's
     * « avant / pendant / après » rows.
     *
     * @param bool $hasExtras whether a document or an assignment hangs off that part
     */
    public function sectionStateOf(?string $content, bool $hasExtras): string
    {
        if ('filled' === $this->stateOf($content)) {
            return 'filled';
        }

        return $hasExtras ? 'partial' : 'empty';
    }
}
