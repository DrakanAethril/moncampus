<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The « univers » a formation plays in - the four of
 * design/design_handoff_gamification/data/gamification.json, reproduced without invention.
 *
 * It decides two things and no more: the wording of the six levels
 * (App\Entity\GameLevelLabel) and which catalogue of figures a student draws their pseudonym from
 * (App\Entity\GameFigure). A formation carrying none of them falls back on generic level wording
 * and has no figure catalogue - never on an empty cell.
 *
 * Deliberately an enum rather than a table: the value is stamped on stored rows (a figure, a level
 * label), and a filière is not something an establishment adds on a Tuesday. Adding one is a case
 * here plus a column on one screen.
 */
enum GameTrack: string
{
    case Slam = 'SLAM';
    case Sisr = 'SISR';
    case Cg = 'CG';
    case Mco = 'MCO';

    public function labelKey(): string
    {
        return match ($this) {
            self::Slam => 'gameTrackSlamLabel',
            self::Sisr => 'gameTrackSisrLabel',
            self::Cg => 'gameTrackCgLabel',
            self::Mco => 'gameTrackMcoLabel',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Slam => '💻',
            // Not U+1F5A7 « networked computers »: it has no glyph in the interface fonts and
            // showed as a tofu box on the student's own title screen.
            self::Sisr => '🌐',
            self::Cg => '🧾',
            self::Mco => '🤝',
        };
    }
}
