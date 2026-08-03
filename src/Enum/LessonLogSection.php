<?php

namespace App\Enum;

/**
 * Les trois temps d'un cahier de texte (design_handoff_cahier_de_texte 2a) : ce qui est demandé
 * avant la séance, ce qui y a été fait, ce qui est donné après. Chaque temps porte sa propre
 * visibilité côté étudiant, et un travail comme un document se rattache à l'un d'eux.
 *
 * Les trois champs de texte existaient déjà sur LessonLog sous ces trois noms ; cette énumération
 * les nomme pour ce qui doit désormais s'y rattacher.
 */
enum LessonLogSection: string
{
    case Before = 'before';
    case During = 'during';
    case After = 'after';

    public function labelKey(): string
    {
        return match ($this) {
            self::Before => 'lessonLogSectionBeforeLabel',
            self::During => 'lessonLogSectionDuringLabel',
            self::After => 'lessonLogSectionAfterLabel',
        };
    }

    // Pastille numérotée de l'en-tête de section : ambre, bleue, grise (maquette 2a).
    public function badgeClass(): string
    {
        return match ($this) {
            self::Before => 'cm-step--gold',
            self::During => 'cm-step--blue',
            self::After => 'cm-step--gray',
        };
    }

    public function number(): int
    {
        return match ($this) {
            self::Before => 1,
            self::During => 2,
            self::After => 3,
        };
    }

    /**
     * Un travail se donne avant ou après la séance ; le temps « pendant » est le compte rendu de
     * ce qui a été fait, il ne porte que du texte et des documents.
     *
     * @return list<self>
     */
    public static function acceptingWork(): array
    {
        return [self::Before, self::After];
    }
}
