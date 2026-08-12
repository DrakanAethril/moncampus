<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessConditionHost;
use App\Entity\User;
use App\Security\StructureAccessChecker;

/**
 * The access-condition authority the screens call, in the lineage of HelpAccess and
 * StructureAccessChecker: one place answers "may this reader open this, and if not, what does the
 * row say".
 *
 * The decision itself is AccessConditionEvaluator's, and stays a pure function on primitives. What
 * this adds is everything the rule needs to be true in the application rather than on paper:
 *
 * - the teacher's short-circuit, resolved here rather than passed in - a caller that could choose
 *   would eventually choose wrong, exactly as CourseSpaceBoard argues about unpublished content;
 * - the facts loaded once for a whole screen, so thirty rows cost what one costs;
 * - an object already begun kept open (AccessConditionTraces);
 * - the sentences, built by the labeler from names this reader is entitled to.
 *
 * The verdict is per student and nothing is cached across users: two students of one class read
 * two different screens, and a cache shared between them would be the bug that ships the other
 * one's remediation.
 */
class AccessConditionGate
{
    public function __construct(
        private readonly AccessConditionFactsLoader $factsLoader,
        private readonly AccessConditionEvaluator $evaluator,
        private readonly AccessConditionNameResolver $nameResolver,
        private readonly AccessConditionLabeler $labeler,
        private readonly AccessConditionTraces $traces,
        private readonly StructureAccessChecker $accessChecker,
    ) {
    }

    /**
     * @param list<AccessConditionHost> $hosts
     */
    public function verdicts(array $hosts, User $reader, ?\DateTimeImmutable $now = null): AccessConditionVerdictMap
    {
        $conditioned = [];
        $trees = [];

        foreach ($hosts as $host) {
            $tree = $host->getAccessConditionTree();
            $program = $host->getAccessConditionProgram();

            // Unconditional objects, and everything a teacher reads, never reach the loader: an
            // ordinary screen carrying no condition must not pay a single query for the feature.
            if (null === $tree || (null !== $program && $this->readsThrough($program))) {
                continue;
            }

            $conditioned[] = $host;
            $trees[AccessConditionHostKey::of($host)] = $tree;
        }

        if ([] === $trees) {
            return new AccessConditionVerdictMap();
        }

        $facts = $this->factsLoader->load(array_values($trees), $reader, $now);
        $verdicts = $this->evaluator->evaluateMany($trees, $facts);

        return new AccessConditionVerdictMap($this->explain($verdicts, $conditioned, $reader, $facts));
    }

    public function verdict(AccessConditionHost $host, User $reader, ?\DateTimeImmutable $now = null): AccessConditionVerdict
    {
        return $this->verdicts([$host], $reader, $now)->of($host);
    }

    public function isOpen(AccessConditionHost $host, User $reader, ?\DateTimeImmutable $now = null): bool
    {
        return $this->verdict($host, $reader, $now)->satisfied;
    }

    /**
     * Reopens what the student has already begun, then writes the sentences of what stays shut.
     *
     * Both passes only ever look at the objects that failed, which is what keeps them free on an
     * ordinary screen: nothing failed, nothing is resolved and nothing is named.
     *
     * @param array<string, AccessConditionVerdict> $verdicts
     * @param list<AccessConditionHost>             $hosts
     *
     * @return array<string, AccessConditionVerdict>
     */
    private function explain(array $verdicts, array $hosts, User $reader, StudentAccessFacts $facts): array
    {
        $failed = array_values(array_filter(
            $hosts,
            static fn (AccessConditionHost $host): bool => !($verdicts[AccessConditionHostKey::of($host)]->satisfied ?? true),
        ));

        if ([] === $failed) {
            return $verdicts;
        }

        foreach (array_keys($this->traces->startedHostKeys($failed, $reader)) as $key) {
            $verdicts[$key] = AccessConditionVerdict::open();
        }

        $unmet = [];
        foreach ($verdicts as $verdict) {
            foreach ($verdict->unmet as $leaf) {
                $unmet[] = $leaf;
            }
        }

        if ([] === $unmet) {
            return $verdicts;
        }

        $names = $this->nameResolver->resolve($unmet, $reader);

        foreach ($verdicts as $key => $verdict) {
            if (!$verdict->satisfied) {
                $verdicts[$key] = $verdict->withReasons($this->labeler->reasons($verdict->unmet, $names, $facts));
            }
        }

        return $verdicts;
    }

    private function readsThrough(\App\Entity\Program $program): bool
    {
        return $this->accessChecker->isStaff() || $this->accessChecker->isProgramTeacher($program);
    }
}
