<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\SeriousGame\Rules\RewardRules;

/**
 * The collection pieces a game offers, named (design/validated/serious-games.md §8): its trophy,
 * its pin, one card per chapter and the final test's card. Read from the game's manifest; the
 * drawings are `public/serious-games/shared/rewards.js`'s.
 */
final class SeriousGameRewardCatalog
{
    /** @return list<string> every reward code of a game */
    public static function codes(SeriousGameManifest $manifest, string $game): array
    {
        $codes = [];
        if (null !== ($trophy = $manifest->trophy())) {
            $codes[] = $trophy['code'];
        }
        if (null !== ($pin = $manifest->pin())) {
            $codes[] = $pin['code'];
        }
        if ($manifest->hasCards()) {
            foreach ($manifest->chapters() as $chapter) {
                $codes[] = RewardRules::card($game, $chapter['code']);
            }
            // A scenario's last ticket is one of its last act's: no card of its own.
            if (!$manifest->isScenario()) {
                $codes[] = RewardRules::card($game, $manifest->finalCode());
            }
        }

        return $codes;
    }

    /**
     * @return array{kind: string, name: string, lore: string, chapter: ?string}
     */
    public static function describe(SeriousGameManifest $manifest, string $game, string $code): array
    {
        $trophy = $manifest->trophy();
        if (null !== $trophy && $trophy['code'] === $code) {
            return ['kind' => 'trophy', 'name' => $trophy['label'], 'lore' => $trophy['lore'], 'chapter' => null];
        }

        $pin = $manifest->pin();
        if (null !== $pin && $pin['code'] === $code) {
            return ['kind' => 'pin', 'name' => $pin['label'], 'lore' => '', 'chapter' => null];
        }

        foreach ($manifest->chapters() as $chapter) {
            if (RewardRules::card($game, $chapter['code']) === $code) {
                return ['kind' => 'card', 'name' => $chapter['label'], 'lore' => $chapter['topic'], 'chapter' => $chapter['code']];
            }
        }

        if (RewardRules::card($game, $manifest->finalCode()) === $code) {
            return ['kind' => 'card', 'name' => $manifest->finalLabel(), 'lore' => '', 'chapter' => $manifest->finalCode()];
        }

        return ['kind' => 'unknown', 'name' => $code, 'lore' => '', 'chapter' => null];
    }
}
