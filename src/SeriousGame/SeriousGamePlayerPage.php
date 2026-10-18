<?php

declare(strict_types=1);

namespace App\SeriousGame;

use App\Entity\SeriousGame;
use App\Entity\SeriousGameRewardGrant;
use App\Entity\SeriousGameSession;
use App\Entity\User;
use App\Enum\SeriousGameBrightness;
use App\Enum\SeriousGameSessionMode;
use App\Repository\SeriousGameCometRepository;
use App\Repository\SeriousGameProgressRepository;
use App\Repository\SeriousGameRepository;
use App\Repository\SeriousGameRewardGrantRepository;
use App\Repository\SeriousGameSessionRepository;
use App\SeriousGame\Avatar\SeriousGameAvatars;
use App\Service\SeriousGame\SeriousGameAccess;
use App\Service\SeriousGame\SeriousGameRules;

/**
 * « Ma page joueur » (design/validated/serious-games.md §8, design/conception_serious_games/profil.html):
 * the avatar, where the player stands in each game, the parties played, the comets, and the
 * showcase of what they collected. The same reading for the student and - read only - for the
 * teachers of their classes and the administrators (SeriousGameVoter::READ_STUDENT).
 *
 * It **describes, never recommends**: each game carries a statement computed from the progress
 * (« 3 étoiles éteintes dans Le Sablier… »), never « recommandé pour toi », and the history shows
 * what was played, never the days that were not.
 */
/**
 * @phpstan-import-type Progress from SeriousGamePlay
 */
final readonly class SeriousGamePlayerPage
{
    public const int HISTORY = 40;

    public function __construct(
        private SeriousGamePlay $play,
        private SeriousGameRepository $games,
        private SeriousGameProgressRepository $progress,
        private SeriousGameSessionRepository $sessions,
        private SeriousGameCometRepository $comets,
        private SeriousGameRewardGrantRepository $grants,
        private SeriousGameAvatars $avatars,
        private SeriousGameAccess $access,
    ) {
    }

    /**
     * @param bool $own whether the reader is the player (their own page, with its gestures)
     *
     * @return array<string, mixed>
     */
    public function build(User $player, bool $own): array
    {
        $collects = SeriousGameRules::canCollect($player->getRoles());
        $played = $this->progress->findForPlayer($player);
        $games = [];
        foreach ($this->games->findAllOrdered() as $game) {
            $playable = $this->access->canPlay($player, $game);
            if (!$playable && !isset($played[(int) $game->getId()])) {
                continue;
            }
            $games[] = $this->game($player, $game, $playable && $own);
        }

        $grants = $collects ? $this->grants->findForStudent($player) : [];
        $held = [];
        foreach ($grants as $grant) {
            $held[$grant->getRewardCode()] = $grant;
        }

        return [
            'player' => $player,
            'own' => $own,
            'collects' => $collects,
            'avatar' => $this->avatars->configFor($player),
            'games' => $games,
            'showcase' => $collects ? $this->showcase($held) : [],
            'pins' => $collects ? $this->avatars->heldPins($player) : [],
        ];
    }

    /** @return array<string, mixed> */
    private function game(User $player, SeriousGame $game, bool $canPlay): array
    {
        $manifest = SeriousGamePlay::manifest($game);
        $progress = $this->play->progress($player, $game);
        $sessions = $this->sessions->findEndedFor($player, $game, self::HISTORY);
        $source = $this->play->source($game);

        return [
            'game' => $game,
            'canPlay' => $canPlay,
            'progress' => $progress,
            'statement' => self::statement($manifest, $progress),
            'history' => array_map(fn (SeriousGameSession $s): array => $this->historyRow($manifest, $s), $sessions),
            'comets' => array_map(static fn ($comet): array => [
                'misconception' => $comet->getMisconception(),
                'label' => $source->current($comet->getUnitCode())?->misconceptionLabel($comet->getMisconception()) ?? $comet->getMisconception(),
                'unit' => $manifest->unit($comet->getUnitCode())['label'] ?? $comet->getUnitCode(),
                'state' => $comet->getState()->value,
                'appearedAt' => $comet->getAppearedAt(),
                'solvedAt' => $comet->getSolvedAt(),
                'attempts' => $comet->getAttempts(),
            ], $this->comets->findForPlayer($player, $game)),
        ];
    }

    /**
     * The statement of one game, in the four cases of the conception - computed, never written.
     *
     * @param Progress $progress
     *
     * @return array{key: string, params: array<string, string|int>, finish: ?string}
     */
    public static function statement(SeriousGameManifest $manifest, array $progress): array
    {
        $units = $progress['units'];
        $final = $progress['final'];
        $played = array_sum(array_column($units, 'series')) > 0;

        if (!$played && null === $final['best']) {
            return ['key' => 'seriousGameStatementNew', 'params' => ['%chapters%' => \count($manifest->chapters()), '%total%' => $progress['total']], 'finish' => null];
        }

        if (null !== $final['best']) {
            $finish = null;
            foreach ($progress['rewards'] as $reward) {
                if ('trophy' === $reward['kind']) {
                    $finish = $reward['finish'];
                }
            }

            return ['key' => 'seriousGameStatementFinalPlayed', 'params' => ['%final%' => $final['label'], '%best%' => $final['best']], 'finish' => $finish];
        }

        if (true === $progress['finished']) {
            return ['key' => 'seriousGameStatementFinalOpen', 'params' => ['%final%' => $final['label']], 'finish' => null];
        }

        // The unlit stars of the most advanced constellation that is not complete.
        $best = null;
        foreach ($progress['chapters'] as $chapter) {
            if ($chapter['lit'] < $chapter['total'] && (null === $best || $chapter['lit'] / max(1, $chapter['total']) > $best['lit'] / max(1, $best['total']))) {
                $best = $chapter;
            }
        }
        $unlit = array_values(array_filter($units, static fn (array $u): bool => !$u['lit'] && !$u['hidden']));
        $inBest = null === $best ? [] : array_values(array_filter($unlit, static fn (array $u): bool => $u['chapter'] === $best['code']));

        return ['key' => 'seriousGameStatementProgress', 'params' => [
            '%count%' => \count($unlit),
            '%inChapter%' => \count($inBest),
            '%chapter%' => null === $best ? '' : $best['label'],
            '%units%' => implode(', ', array_column($inBest, 'label')),
        ], 'finish' => null];
    }

    /** @return array<string, mixed> */
    private function historyRow(SeriousGameManifest $manifest, SeriousGameSession $session): array
    {
        return [
            'session' => $session,
            'mode' => $session->getMode()->value,
            'unit' => null === $session->getUnitCode() ? null : ($manifest->unit($session->getUnitCode())['label'] ?? $session->getUnitCode()),
            'score' => $session->getScore(),
            'size' => $session->getSize(),
            'endedAt' => $session->getEndedAt(),
            'brightness' => SeriousGameSessionMode::Series === $session->getMode() ? SeriousGameBrightness::fromBest($session->getScore()) : null,
        ];
    }

    /**
     * The showcase of L'Observatoire's pieces - every slot, held or silhouetted.
     *
     * @param array<string, SeriousGameRewardGrant> $held
     *
     * @return list<array<string, mixed>>
     */
    private function showcase(array $held): array
    {
        $shelves = [];
        foreach ($this->games->findAllOrdered() as $game) {
            $manifest = SeriousGamePlay::manifest($game);
            $pieces = [];
            foreach (SeriousGameRewardCatalog::codes($manifest, $game->getSlug()) as $code) {
                $grant = $held[$code] ?? null;
                $pieces[] = ['code' => $code, 'held' => null !== $grant, 'finish' => $grant?->getFinish()?->value, 'grantedAt' => $grant?->getGrantedAt()] + SeriousGameRewardCatalog::describe($manifest, $game->getSlug(), $code);
            }
            if ([] !== $pieces) {
                $shelves[] = ['game' => $game, 'pieces' => $pieces];
            }
        }

        return $shelves;
    }
}
