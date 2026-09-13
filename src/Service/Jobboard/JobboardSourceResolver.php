<?php

declare(strict_types=1);

namespace App\Service\Jobboard;

use App\Entity\JobboardSource;
use App\Enum\JobboardLearningKind;
use App\Repository\JobboardSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which site does this offer come from? The one question that used to be answered by a closed enum.
 *
 * **The URL is the truth, the declared name is a hint.** That order is the whole design, and it is
 * strictly stronger than the check it replaces:
 *
 * 1. the host belongs to a known source's domains → that source, *whatever the agent declared*. A
 *    batch labelled `hellowrk` used to be refused offer by offer with `unknown_source`; it now
 *    files under HelloWork, and no typo can ever fork a site into two rows;
 * 2. otherwise the declared name matches a known source → that source, and the host's domain is
 *    **attached** to it. This is how a site that moves (a new domain, a regional one) is followed
 *    rather than refused, and how a source first seen through a cursor - which carries no URL -
 *    gets its domain the day its first offer arrives;
 * 3. otherwise → a new source, built from the domain and named as declared.
 *
 * Nothing here ever refuses. Refusing was the behaviour being removed: the sites turn over faster
 * than a deploy, and an offer refused is an offer lost, since nothing stores what it refused.
 *
 * **But everything it decides is written down**, which is the counterweight to never refusing: a
 * resolution silent by design must not also be invisible. The two gestures that change the table
 * for next time - a site created, a domain attached to an existing site - are journalled as they
 * happen and handed to whoever asked for the resolution (`takeLearned()`). An administrator reads
 * them on « Configuration > Jobboard > Historique », the agent reads them in the answer to its own
 * call, and neither reading refuses anything: it is a diagnostic, not a gate.
 *
 * **`ResetInterface`, and it is load-bearing rather than tidy.** The table is read once per request
 * and kept, because a batch of 500 offers would otherwise re-read it 500 times, and because a
 * source created on row 12 must be found again on row 13 - before any flush, where no query could
 * see it. FrankenPHP serves this application in worker mode: without reset(), the second request
 * would answer with the first one's table, and a source added meanwhile would stay invisible for
 * as long as the worker lives.
 */
final class JobboardSourceResolver implements ResetInterface
{
    /**
     * Every source, read once. Null means "not read yet" - distinct from an empty table, which is
     * the legitimate state of a fresh install.
     *
     * @var list<JobboardSource>|null
     */
    private ?array $known = null;

    /**
     * The gestures made since the last drain, deduplicated: forty offers of one new site are one
     * creation, and a dry run - which never attaches anything, so it meets the same unknown host on
     * every line - must not announce forty attachments of the same domain.
     *
     * @var array<string, SourceLearning>
     */
    private array $learned = [];

    public function __construct(
        private readonly JobboardSourceRepository $sources,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The real pass: may attach a domain to a source, may create one. Nothing is flushed - the
     * ingestor owns the transaction and writes the offers in the same one.
     */
    public function resolve(string $declared, string $host): JobboardSource
    {
        return $this->match($declared, $host, true);
    }

    /**
     * The dry run of « Configuration > Jobboard > Import », which must announce exactly what the
     * real pass will do and write nothing at all. A source it invents is never persisted; it exists
     * for the length of the screen that prints its name.
     */
    public function preview(string $declared, string $host): JobboardSource
    {
        return $this->match($declared, $host, false);
    }

    /**
     * By declared name alone, for the two calls that carry no URL. `create: false` is the closing
     * call - naming a site no offer ever came from closes nothing, so there is nothing to create;
     * `create: true` is the cursor, which an agent legitimately writes before its first batch.
     *
     * Null only when the name itself is unusable (empty, or nothing but punctuation).
     */
    public function byName(string $declared, bool $create = false): ?JobboardSource
    {
        $normalised = self::normalise($declared);

        if ('' === $normalised) {
            return null;
        }

        $found = $this->byNormalisedName($normalised);

        if (null !== $found) {
            return $found;
        }

        return $create ? $this->create($declared, $normalised, null) : null;
    }

    /**
     * A site an administrator declares by hand, ahead of the veille - typically to give it the
     * `source_ref` rule and the legacy prefix that a site discovered on the fly cannot have.
     *
     * The slug is derived here rather than typed: it is the identifier the API echoes and the
     * filters carry, and letting two rows fight over one would break the lookup it exists for.
     *
     * @param list<string> $domains
     */
    public function declareSite(string $label, array $domains): JobboardSource
    {
        $source = new JobboardSource($this->freeSlug(self::normalise($label)), mb_substr(trim($label), 0, 64));
        $source->setDomains($domains);

        $this->entityManager->persist($source);
        $this->known = [...$this->all(), $source];

        return $source;
    }

    /**
     * The journal, emptied as it is read. Draining rather than reading is what keeps two deposits
     * in one process from inheriting each other's gestures - and the caller is the only thing that
     * knows which lot they belong to.
     *
     * @return list<SourceLearning>
     */
    public function takeLearned(): array
    {
        $learned = array_values($this->learned);
        $this->learned = [];

        return $learned;
    }

    public function reset(): void
    {
        $this->known = null;
        $this->learned = [];
    }

    /**
     * The host an offer's URL points at, or null when the string is not a link a student could
     * click. Lowercased, trailing dot and leading `www.` dropped, so the three spellings of one
     * site are one host.
     *
     * Only `http` and `https`: the URL is rendered as an `href` on the detail panel, and a
     * `javascript:` or `data:` scheme reaching that attribute is not a data-quality question.
     */
    public static function hostOf(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (false === $parts) {
            return null;
        }

        $scheme = \is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : '';
        $host = \is_string($parts['host'] ?? null) ? strtolower(rtrim($parts['host'], '.')) : '';

        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host || !str_contains($host, '.')) {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function match(string $declared, string $host, bool $learn): JobboardSource
    {
        foreach ($this->all() as $source) {
            if ($source->covers($host)) {
                return $source;
            }
        }

        $normalised = self::normalise($declared);
        $named = '' === $normalised ? null : $this->byNormalisedName($normalised);

        if (null !== $named) {
            // The site moved, or it was first met through a cursor and had no domain yet. Attaching
            // rather than refusing is the point; the screen lists the domains of every source, so a
            // redirector picked up by accident is visible and removable.
            $domain = self::registrable($host);

            if ($learn) {
                $named->addDomain($domain);
            }

            $this->journal(JobboardLearningKind::DomainAttached, $named, $declared, $domain);

            return $named;
        }

        return $this->create($declared, $normalised, $host, $learn);
    }

    private function create(string $declared, string $normalised, ?string $host, bool $persist = true): JobboardSource
    {
        $label = trim($declared);
        $domain = null === $host ? null : self::registrable($host);

        if ('' === $label) {
            $label = $domain ?? $normalised;
        }

        $source = new JobboardSource(
            $this->freeSlug('' !== $normalised ? $normalised : (string) preg_replace('/[^a-z0-9]+/', '', (string) $domain)),
            mb_substr($label, 0, 64),
            null === $domain ? [] : [$domain],
        );
        $source->markDiscoveredByAgent();

        if ($persist) {
            $this->entityManager->persist($source);
        }

        // Added to the in-memory table either way: two offers of the same new site inside one batch
        // are one source, and a dry run that announced two would be announcing a pass that will not
        // happen.
        $this->known = [...$this->all(), $source];

        // Journalled only when a URL brought it. A site created by name alone comes from a cursor
        // write, which belongs to no deposit of offers - and it is already legible on the sources
        // screen, which says of every row whether a human decided it or the veille met it.
        if (null !== $domain) {
            $this->journal(JobboardLearningKind::SourceCreated, $source, $declared, $domain);
        }

        return $source;
    }

    /** First writing wins: the gesture is the change, and the change happens once. */
    private function journal(JobboardLearningKind $kind, JobboardSource $source, string $declared, string $domain): void
    {
        $learning = new SourceLearning($kind, $source, mb_substr(trim($declared), 0, 255), $domain);

        $this->learned[$learning->identity()] ??= $learning;
    }

    private function byNormalisedName(string $normalised): ?JobboardSource
    {
        foreach ($this->all() as $source) {
            if ($normalised === $source->getSlug() || $normalised === self::normalise($source->getLabel())) {
                return $source;
            }
        }

        return null;
    }

    /** @return list<JobboardSource> */
    private function all(): array
    {
        return $this->known ??= $this->sources->findAllOrdered();
    }

    private function freeSlug(string $wanted): string
    {
        $base = '' === $wanted ? 'source' : mb_substr($wanted, 0, 32);
        $slug = $base;
        $suffix = 1;

        while (null !== $this->slugHolder($slug)) {
            ++$suffix;
            $slug = mb_substr($base, 0, 32 - \strlen((string) $suffix)).$suffix;
        }

        return $slug;
    }

    private function slugHolder(string $slug): ?JobboardSource
    {
        foreach ($this->all() as $source) {
            if ($slug === $source->getSlug()) {
                return $source;
            }
        }

        return null;
    }

    /**
     * What gets stored as a domain when a site is met for the first time: the registrable part of
     * the host, so `candidat.francetravail.fr` files under `francetravail.fr` and its siblings come
     * back to the same source.
     *
     * The two-label rule is wrong for `co.uk` and friends, where it would swallow every British
     * site into one source - hence the short list. It is a heuristic, not a public-suffix
     * implementation, and it is allowed to be: the screen shows every domain and an administrator
     * corrects one in two clicks.
     */
    private static function registrable(string $host): string
    {
        $labels = explode('.', $host);
        $count = \count($labels);

        if ($count <= 2) {
            return $host;
        }

        $second = $labels[$count - 2];
        $depth = 2 === \strlen($labels[$count - 1]) && \in_array($second, ['co', 'com', 'org', 'net', 'gov', 'ac', 'edu'], true) ? 3 : 2;

        return implode('.', \array_slice($labels, -$depth));
    }

    /**
     * The spelling-insensitive form a declared name is compared on: « France Travail », `france-travail`
     * and `FranceTravail` are all `francetravail`.
     *
     * Transliterated first, so an accent does not eat a letter - « Métiers » must normalise to
     * `metiers`, not to `mtiers`, or two spellings of the same site would stop meeting.
     */
    private static function normalise(string $value): string
    {
        $ascii = (new AsciiSlugger())->slug(trim($value), '-')->lower()->toString();

        return (string) preg_replace('/[^a-z0-9]+/', '', $ascii);
    }
}
