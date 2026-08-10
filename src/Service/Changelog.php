<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ReleaseEntryType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads config/changelog.yaml - what shipped to production, release by release.
 *
 * A file in the repository rather than a table, for one reason: the changelog *is* part of the
 * release. It travels with the code, so the moment a deploy finishes, production is already
 * describing itself; there is no command to remember to run on the server, which is the wrinkle the
 * help centre's own content still has.
 *
 * The file is written by hand (by /beaup-deploy at deploy time), so nothing here is fatal: a
 * release missing a version, a date that will not parse, an entry that is not a mapping - each is
 * dropped on its own and the page still renders. A changelog that takes the site down would be a
 * poor trade for a typo.
 *
 * @phpstan-type RawEntry array{type?: mixed, title?: mixed, detail?: mixed}
 * @phpstan-type RawRelease array{version?: mixed, date?: mixed, summary?: mixed, entries?: mixed}
 */
class Changelog
{
    /** @var list<Release>|null */
    private ?array $releases = null;

    private readonly string $path;

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')] string $projectDir,
        ?string $path = null,
    ) {
        $this->path = $path ?? $projectDir.'/config/changelog.yaml';
    }

    /**
     * Newest release first.
     *
     * @return list<Release>
     */
    public function releases(): array
    {
        if (null !== $this->releases) {
            return $this->releases;
        }

        if (!is_file($this->path)) {
            return $this->releases = [];
        }

        $parsed = Yaml::parseFile($this->path);

        return $this->releases = self::parse(is_array($parsed) ? $parsed : []);
    }

    /** The version production is running - what the "À propos" screen shows. */
    public function currentVersion(): ?string
    {
        return $this->releases()[0]->version ?? null;
    }

    public function current(): ?Release
    {
        return $this->releases()[0] ?? null;
    }

    /**
     * The typed reading of the parsed file - the boundary, kept separate so it can be exercised
     * without a file on disk.
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<Release>
     */
    public static function parse(array $data): array
    {
        $rawReleases = $data['releases'] ?? null;

        if (!is_array($rawReleases)) {
            return [];
        }

        $releases = [];
        foreach ($rawReleases as $rawRelease) {
            $release = self::release($rawRelease);
            if (null !== $release) {
                $releases[] = $release;
            }
        }

        // The file is meant to be written newest-first, but nothing enforces it and a release
        // appended at the bottom by mistake should still show up in the right place.
        usort($releases, static fn (Release $a, Release $b): int => $b->date <=> $a->date);

        return $releases;
    }

    private static function release(mixed $raw): ?Release
    {
        if (!is_array($raw)) {
            return null;
        }

        $version = self::string($raw['version'] ?? null);
        $date = self::date($raw['date'] ?? null);

        if ('' === $version || null === $date) {
            return null;
        }

        $rawEntries = $raw['entries'] ?? [];
        $entries = [];
        foreach (is_array($rawEntries) ? $rawEntries : [] as $rawEntry) {
            $entry = self::entry($rawEntry);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return new Release($version, $date, self::string($raw['summary'] ?? null), $entries);
    }

    private static function entry(mixed $raw): ?ReleaseEntry
    {
        if (!is_array($raw)) {
            return null;
        }

        $title = self::string($raw['title'] ?? null);

        if ('' === $title) {
            return null;
        }

        $detail = self::string($raw['detail'] ?? null);

        return new ReleaseEntry(
            ReleaseEntryType::tryFrom(self::string($raw['type'] ?? null)) ?? ReleaseEntryType::Other,
            $title,
            '' !== $detail ? $detail : null,
        );
    }

    private static function date(mixed $raw): ?\DateTimeImmutable
    {
        // The YAML parser turns an unquoted 2026-08-10 into a timestamp on its own; a quoted one
        // stays a string. Both are accepted rather than imposing one on whoever writes the file.
        if (is_int($raw)) {
            return (new \DateTimeImmutable())->setTimestamp($raw);
        }

        $value = self::string($raw);

        if ('' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function string(mixed $raw): string
    {
        return is_scalar($raw) ? trim((string) $raw) : '';
    }
}
