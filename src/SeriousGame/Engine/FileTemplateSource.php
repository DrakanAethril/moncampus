<?php

declare(strict_types=1);

namespace App\SeriousGame\Engine;

use App\SeriousGame\Template\TemplateDefinition;

/**
 * The templates shipped in `config/serious_games/{slug}/templates/{unit}.json`, read as version 1 -
 * what `app:serious-games:sync` installs, and what the bench and the tests read without a database.
 */
final class FileTemplateSource implements TemplateSource
{
    /** @var array<string, TemplateDefinition|null> */
    private array $loaded = [];

    public function __construct(private readonly string $directory)
    {
    }

    public function current(string $unit): ?TemplateDefinition
    {
        if (!\array_key_exists($unit, $this->loaded)) {
            $path = $this->directory.'/'.$unit.'.json';
            $this->loaded[$unit] = is_file($path) ? TemplateDefinition::fromFile($path) : null;
        }

        return $this->loaded[$unit];
    }

    public function version(string $unit, int $version): ?TemplateDefinition
    {
        return 1 === $version ? $this->current($unit) : null;
    }

    /** @return list<string> the units that have a file */
    public function units(): array
    {
        $units = array_map(static fn (string $path): string => basename($path, '.json'), glob($this->directory.'/*.json') ?: []);
        sort($units);

        return $units;
    }
}
