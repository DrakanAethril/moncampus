<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The three sizes a word cloud is drawn at, and the palette each is drawn in
 * (design_handoff_nuage_de_mots, « Règle de pondération du nuage »).
 *
 * The three exist because they are read from three distances: over the teacher's own shoulder, on
 * a board beside a panel of figures, and on a board that is nothing but the cloud. Only `lo` and
 * `hi` change - the formula and the colour ladder are the same everywhere, which is what makes the
 * pilot preview a faithful rehearsal of what the class will see.
 *
 * The **palette** is deliberately not here. The handoff gives one for a dark ground and one for a
 * light ground, and which of the two applies is a question about the theme rather than about the
 * scale: the pilot preview sits on a white card in the light theme and on a dark one in the other.
 * It is therefore settled in CSS, where the theme lives - App\Service\WordCloud\WordCloudWeighting
 * answers how *often* a word was cited, and the stylesheet answers what that looks like.
 */
enum WordCloudScale: string
{
    /** The preview inside the pilot screen, on a white card. */
    case PilotPreview = 'pilot';

    /** Projected beside « Les plus cités » / « Derniers arrivés ». */
    case ProjectionWithPanel = 'projection_panel';

    /** Projected alone, the whole board. */
    case ProjectionFull = 'projection_full';

    /** Font size of the least cited word. */
    public function minSize(): int
    {
        return match ($this) {
            self::PilotPreview => 15,
            self::ProjectionWithPanel => 20,
            self::ProjectionFull => 26,
        };
    }

    /** Font size of the most cited one. */
    public function maxSize(): int
    {
        return match ($this) {
            self::PilotPreview => 44,
            self::ProjectionWithPanel => 60,
            self::ProjectionFull => 92,
        };
    }
}
