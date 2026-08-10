<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * The two figures that cannot be counted from the deployed application, and the day they were taken.
 *
 * .git/ and tests/ are excluded from the production image (see .dockerignore), so neither the number
 * of commits nor the number of tests is readable there. They live in config/tech_profile.yaml and are
 * refreshed by /beaup-deploy at each release.
 *
 * A class of its own rather than a private method of App\Service\TechnicalProfile, because the
 * changelog page wants the commit count without paying for that class's full file walk.
 */
class MeasuredProfile
{
    /** @var array{commits: int, tests: int, measuredAt: string}|null */
    private ?array $values = null;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {
    }

    public function commits(): int
    {
        return $this->values()['commits'];
    }

    public function tests(): int
    {
        return $this->values()['tests'];
    }

    /** Empty when the file is missing - the screens show the date beside the figures, or nothing. */
    public function measuredAt(): string
    {
        return $this->values()['measuredAt'];
    }

    /** @return array{commits: int, tests: int, measuredAt: string} */
    private function values(): array
    {
        if (null !== $this->values) {
            return $this->values;
        }

        $path = $this->projectDir.'/config/tech_profile.yaml';

        if (!is_file($path)) {
            return $this->values = ['commits' => 0, 'tests' => 0, 'measuredAt' => ''];
        }

        $parsed = Yaml::parseFile($path);
        $data = is_array($parsed) ? $parsed : [];
        $measuredAt = $data['measured_at'] ?? '';

        return $this->values = [
            'commits' => is_numeric($data['commits'] ?? null) ? (int) $data['commits'] : 0,
            'tests' => is_numeric($data['tests'] ?? null) ? (int) $data['tests'] : 0,
            'measuredAt' => is_scalar($measuredAt) ? (string) $measuredAt : '',
        ];
    }
}
