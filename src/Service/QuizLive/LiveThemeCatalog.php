<?php

declare(strict_types=1);

namespace App\Service\QuizLive;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The live contest's themes, read once from config/quiz_live/themes.yaml (design/validated/
 * quiz-live-public.md): the permanent ones first, then the seasonal ones, in the file's order. The
 * default is the one a new contest is offered - Pop.
 *
 * Read strictly: a theme missing a word, a swatch or its season's other end is an error at the
 * first reading, never a screen that writes nothing (tests/Service/QuizLive/LiveThemeCatalogTest.php
 * reads the shipped file).
 */
final class LiveThemeCatalog
{
    private const array WAVES = ['sine', 'triangle', 'square', 'sawtooth'];
    private const array CONFETTI = ['rect', 'circle', 'star', 'glow', 'strip', 'fish'];

    /** @var array<string, LiveTheme>|null */
    private ?array $themes = null;
    private string $defaultSlug = '';

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/quiz_live/themes.yaml')]
        private readonly string $path,
    ) {
    }

    /** @return array<string, LiveTheme> by slug, the permanent themes first */
    public function all(): array
    {
        $this->load();
        \assert(null !== $this->themes);

        $themes = $this->themes;
        uasort($themes, static fn (LiveTheme $a, LiveTheme $b): int => (int) $a->isSeasonal() <=> (int) $b->isSeasonal());

        return $themes;
    }

    /** @return list<LiveTheme> */
    public function permanent(): array
    {
        return array_values(array_filter($this->all(), static fn (LiveTheme $theme): bool => !$theme->isSeasonal()));
    }

    /** @return list<LiveTheme> */
    public function seasonal(): array
    {
        return array_values(array_filter($this->all(), static fn (LiveTheme $theme): bool => $theme->isSeasonal()));
    }

    public function find(string $slug): ?LiveTheme
    {
        return $this->all()[$slug] ?? null;
    }

    public function default(): LiveTheme
    {
        $this->load();

        return $this->all()[$this->defaultSlug];
    }

    /** The contest's theme - the default when a slug no longer names one (a theme taken out). */
    public function resolve(string $slug): LiveTheme
    {
        return $this->find($slug) ?? $this->default();
    }

    private function load(): void
    {
        if (null !== $this->themes) {
            return;
        }

        $data = Yaml::parseFile($this->path);
        if (!\is_array($data) || !\is_array($data['themes'] ?? null) || !\is_string($data['default'] ?? null)) {
            throw new \UnexpectedValueException('The live themes catalogue needs a `default` and `themes`.');
        }

        $themes = [];
        foreach ($data['themes'] as $slug => $entry) {
            if (!\is_string($slug) || 1 !== preg_match('/^[a-z][a-z0-9-]{1,30}$/', $slug) || !\is_array($entry)) {
                throw new \UnexpectedValueException(\sprintf('Live theme "%s" is malformed.', (string) $slug));
            }
            $themes[$slug] = $this->theme($slug, $entry);
        }

        if (!isset($themes[$data['default']])) {
            throw new \UnexpectedValueException('The default live theme is not in the catalogue.');
        }

        $this->themes = $themes;
        $this->defaultSlug = $data['default'];
    }

    /** @param array<array-key, mixed> $entry */
    private function theme(string $slug, array $entry): LiveTheme
    {
        $text = static function (mixed $value, string $what) use ($slug): string {
            if (!\is_string($value) || '' === trim($value)) {
                throw new \UnexpectedValueException(\sprintf('Live theme "%s" has no %s.', $slug, $what));
            }

            return $value;
        };

        $swatches = $entry['swatches'] ?? null;
        if (!\is_array($swatches) || 5 !== \count($swatches)) {
            throw new \UnexpectedValueException(\sprintf('Live theme "%s" needs five swatches.', $slug));
        }
        $colours = [];
        foreach ($swatches as $swatch) {
            if (!\is_string($swatch) || 1 !== preg_match('/^#[0-9A-Fa-f]{6}$/', $swatch)) {
                throw new \UnexpectedValueException(\sprintf('Live theme "%s" has a swatch that is not a colour.', $slug));
            }
            $colours[] = $swatch;
        }

        $rawWords = $entry['words'] ?? null;
        if (!\is_array($rawWords)) {
            throw new \UnexpectedValueException(\sprintf('Live theme "%s" has no words.', $slug));
        }
        $words = [];
        foreach (LiveTheme::WORDS as $key) {
            $words[$key] = $text($rawWords[$key] ?? null, \sprintf('word "%s"', $key));
        }

        $wave = $text($entry['wave'] ?? null, 'wave');
        $confetti = $text($entry['confetti'] ?? null, 'confetti');
        if (!\in_array($wave, self::WAVES, true) || !\in_array($confetti, self::CONFETTI, true)) {
            throw new \UnexpectedValueException(\sprintf('Live theme "%s" names an unknown wave or confetti.', $slug));
        }

        $season = $entry['season'] ?? null;
        $label = $from = $to = null;
        if (null !== $season) {
            if (!\is_array($season)) {
                throw new \UnexpectedValueException(\sprintf('Live theme "%s" has a malformed season.', $slug));
            }
            $label = $text($season['label'] ?? null, 'season label');
            $from = $text($season['from'] ?? null, 'season start');
            $to = $text($season['to'] ?? null, 'season end');
            foreach ([$from, $to] as $day) {
                if (1 !== preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $day)) {
                    throw new \UnexpectedValueException(\sprintf('Live theme "%s" has a season day that is not MM-DD.', $slug));
                }
            }
        }

        return new LiveTheme($slug, $text($entry['name'] ?? null, 'name'), $text($entry['description'] ?? null, 'description'), $colours, $words, $wave, $confetti, $label, $from, $to);
    }
}
