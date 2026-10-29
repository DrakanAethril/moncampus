<?php

declare(strict_types=1);

namespace App\SeriousGame;

/**
 * The structure of a game, as `config/serious_games/{slug}/game.yaml` declares it and
 * SeriousGame::$manifest keeps it (design/validated/serious-games.md §5.6): its chapters and their
 * units, the series, the final test, the comets, the grades and the rewards.
 *
 * The only reader of that JSON - nobody indexes the array by hand.
 *
 * @phpstan-type Unit array{code: string, label: string, chapter: string}
 * @phpstan-type Words array{unit: string, units: string, chapter: string, chapters: string, lit: string, litPlural: string, unlit: string, unlitPlural: string, level: string, skill: string, comet: string, comets: string, cometSolved: string, cometInView: string, chapterDone: string, dim: string, brightness: array<string, string>}
 * @phpstan-type Chapter array{code: string, label: string, topic: string, units: list<Unit>}
 */
final readonly class SeriousGameManifest
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    /** @return list<Chapter> */
    public function chapters(): array
    {
        $chapters = [];
        foreach (self::list($this->data['chapters'] ?? null) as $chapter) {
            if (!\is_array($chapter) || !\is_string($chapter['code'] ?? null)) {
                continue;
            }
            $units = [];
            foreach (self::list($chapter['units'] ?? null) as $unit) {
                if (\is_array($unit) && \is_string($unit['code'] ?? null)) {
                    $units[] = ['code' => $unit['code'], 'label' => \is_string($unit['label'] ?? null) ? $unit['label'] : $unit['code'], 'chapter' => $chapter['code']];
                }
            }
            $chapters[] = [
                'code' => $chapter['code'],
                'label' => \is_string($chapter['label'] ?? null) ? $chapter['label'] : $chapter['code'],
                'topic' => \is_string($chapter['topic'] ?? null) ? $chapter['topic'] : '',
                'units' => $units,
            ];
        }

        return $chapters;
    }

    /** @return list<Unit> every unit, in the order of the chapters */
    public function units(): array
    {
        return array_merge(...array_map(static fn (array $c): array => $c['units'], $this->chapters()) ?: [[]]);
    }

    /** @return list<string> */
    public function unitCodes(): array
    {
        return array_column($this->units(), 'code');
    }

    /** @return Unit|null */
    public function unit(string $code): ?array
    {
        foreach ($this->units() as $unit) {
            if ($unit['code'] === $code) {
                return $unit;
            }
        }

        return null;
    }

    /** Whether the game is played as a scenario of tickets (design/validated/serious-games-neko-con.md §3). */
    public function isScenario(): bool
    {
        return 'scenario' === ($this->data['mechanic'] ?? null);
    }

    public function seriesSize(): int
    {
        return $this->int(['series', 'size'], 12);
    }

    /**
     * How many questions of each difficulty a series climbs through (`series.tiers`, Station
     * Abysse's three depths); [] when a series is drawn regardless of difficulty.
     *
     * @return list<int>
     */
    public function seriesTiers(): array
    {
        $tiers = array_values(array_filter(self::list($this->path(['series', 'tiers'])), is_int(...)));

        return array_sum($tiers) === $this->seriesSize() ? $tiers : [];
    }

    public function litAt(): int
    {
        return $this->int(['series', 'lit_at'], 6);
    }

    public function finalCode(): string
    {
        return $this->string(['final', 'code'], 'final');
    }

    public function finalLabel(): string
    {
        return $this->string(['final', 'label'], 'Épreuve finale');
    }

    public function finalSize(): int
    {
        return $this->int(['final', 'size'], 12);
    }

    public function finalTimeLimit(): int
    {
        return $this->int(['final', 'time_limit_seconds'], 1200);
    }

    /** @return list<int> how many questions each chapter gives the final test, shuffled between them */
    public function finalPerChapter(): array
    {
        $counts = [];
        foreach (self::list($this->path(['final', 'per_chapter'])) as $count) {
            if (\is_int($count)) {
                $counts[] = $count;
            }
        }

        return $counts;
    }

    public function cometWindow(): int
    {
        return $this->int(['comet', 'window'], 30);
    }

    public function cometRepeat(): int
    {
        return $this->int(['comet', 'repeat'], 2);
    }

    public function cometQuestions(): int
    {
        return $this->int(['comet', 'questions'], 3);
    }

    public function cometSolvedAt(): int
    {
        return $this->int(['comet', 'solved_at'], 2);
    }

    /** @return list<array{0: int, 1: string}> */
    public function grades(): array
    {
        $grades = [];
        foreach (self::list($this->data['grades'] ?? null) as $grade) {
            if (\is_array($grade) && \is_int($grade[0] ?? null) && \is_string($grade[1] ?? null)) {
                $grades[] = [$grade[0], $grade[1]];
            }
        }

        return $grades;
    }

    public function gradeFor(int $lit): string
    {
        $title = '';
        foreach ($this->grades() as [$threshold, $label]) {
            if ($lit >= $threshold) {
                $title = $label;
            }
        }

        return $title;
    }

    /** @return array{code: string, label: string, lore: string, silver_at: int, gold_at: int}|null */
    public function trophy(): ?array
    {
        $trophy = $this->path(['rewards', 'trophy']);
        if (!\is_array($trophy) || !\is_string($trophy['code'] ?? null)) {
            return null;
        }

        return [
            'code' => $trophy['code'],
            'label' => \is_string($trophy['label'] ?? null) ? $trophy['label'] : $trophy['code'],
            'lore' => \is_string($trophy['lore'] ?? null) ? $trophy['lore'] : '',
            'silver_at' => \is_int($trophy['silver_at'] ?? null) ? $trophy['silver_at'] : 10,
            'gold_at' => \is_int($trophy['gold_at'] ?? null) ? $trophy['gold_at'] : 12,
        ];
    }

    /** @return array{code: string, label: string}|null */
    public function pin(): ?array
    {
        $pin = $this->path(['rewards', 'pin']);
        if (!\is_array($pin) || !\is_string($pin['code'] ?? null)) {
            return null;
        }

        return ['code' => $pin['code'], 'label' => \is_string($pin['label'] ?? null) ? $pin['label'] : $pin['code']];
    }

    public function hasCards(): bool
    {
        return true === $this->path(['rewards', 'cards']);
    }

    /**
     * The game's own vocabulary (`words` in game.yaml), which Formatool's screens use when they
     * speak of this game: a star is « allumée » in L'Observatoire, a tablet « tracée » in Les
     * Tablettes de Pompéi; a repeated error is a « comète » there, a « lézarde » here. What a
     * manifest does not say is L'Observatoire's word - the first game, written before the key.
     * The sentences that use these words agree in the feminine, as both games' nouns do.
     *
     * @return Words
     */
    public function words(): array
    {
        $pair = function (string $key, string $one, string $many): array {
            $value = $this->path(['words', $key]);
            if (\is_array($value) && \is_string($value[0] ?? null)) {
                return [$value[0], \is_string($value[1] ?? null) ? $value[1] : $value[0]];
            }

            return \is_string($value) ? [$value, $value] : [$one, $many];
        };
        [$unit, $units] = $pair('unit', 'étoile', 'étoiles');
        [$chapter, $chapters] = $pair('chapter', 'constellation', 'constellations');
        [$lit, $litPlural] = $pair('lit', 'allumée', 'allumées');
        [$unlit, $unlitPlural] = $pair('unlit', 'éteinte', 'éteintes');
        [$comet, $comets] = $pair('comet', 'comète', 'comètes');

        $brightness = ['discrete' => 'discrète', 'clear' => 'nette', 'bright' => 'brillante', 'blazing' => 'éclatante'];
        foreach ($brightness as $level => $word) {
            $value = $this->path(['words', 'brightness', $level]);
            $brightness[$level] = \is_array($value) && \is_string($value[0] ?? null) ? $value[0] : (\is_string($value) ? $value : $word);
        }

        return [
            'unit' => $unit, 'units' => $units, 'chapter' => $chapter, 'chapters' => $chapters,
            'lit' => $lit, 'litPlural' => $litPlural, 'unlit' => $unlit, 'unlitPlural' => $unlitPlural,
            'level' => $this->string(['words', 'level'], 'éclat'),
            'skill' => $this->string(['words', 'skill'], 'automatisme'),
            'comet' => $comet, 'comets' => $comets,
            'cometSolved' => $this->string(['words', 'comet_solved'], 'élucidée'),
            'cometInView' => $this->string(['words', 'comet_in_view'], 'en vue'),
            'chapterDone' => $this->string(['words', 'chapter_done'], 'complète'),
            'dim' => $this->string(['words', 'dim_filter'], 'Peu d’éclat'),
            'brightness' => $brightness,
        ];
    }

    /** @return array<string, string> */
    public function thumbnail(): array
    {
        $thumbnail = $this->path(['thumbnail']);
        $out = [];
        foreach (\is_array($thumbnail) ? $thumbnail : [] as $key => $value) {
            if (\is_string($key) && (\is_string($value) || \is_int($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @param list<string> $path */
    private function path(array $path): mixed
    {
        $node = $this->data;
        foreach ($path as $key) {
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }

    /** @param list<string> $path */
    private function int(array $path, int $default): int
    {
        $value = $this->path($path);

        return \is_int($value) ? $value : $default;
    }

    /** @param list<string> $path */
    private function string(array $path, string $default): string
    {
        $value = $this->path($path);

        return \is_string($value) ? $value : $default;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return \is_array($value) ? array_values($value) : [];
    }
}
