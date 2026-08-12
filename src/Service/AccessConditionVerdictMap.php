<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AccessConditionHost;
use App\Enum\AccessConditionDisplay;

/**
 * The verdicts of a whole screen, read back one row at a time - what a template holds while it
 * paints a list.
 *
 * Keyed by host key rather than by object identity so that a Twig loop and the controller that
 * built the map can look the same row up, even when Doctrine hands out two instances of one row.
 */
final readonly class AccessConditionVerdictMap
{
    /** @param array<string, AccessConditionVerdict> $verdicts */
    public function __construct(private array $verdicts = [])
    {
    }

    public function of(AccessConditionHost $host): AccessConditionVerdict
    {
        // An object nobody asked about is open: a screen that forgot to include a row must show it,
        // not lock it. Failing open is the safe side here, as it is for the display default.
        return $this->verdicts[AccessConditionHostKey::of($host)] ?? AccessConditionVerdict::open();
    }

    public function isOpen(AccessConditionHost $host): bool
    {
        return $this->of($host)->satisfied;
    }

    /** Whether the student sees the row at all - the Locked/Hidden choice, applied. */
    public function isVisible(AccessConditionHost $host): bool
    {
        return $this->isOpen($host) || AccessConditionDisplay::Locked === $host->getAccessConditionDisplay();
    }

    /** @return list<string> */
    public function reasonsFor(AccessConditionHost $host): array
    {
        return $this->of($host)->reasons;
    }

    /**
     * The rows a student may see, in the order they came - what a list screen filters on before it
     * even starts drawing.
     *
     * @param list<T> $hosts
     *
     * @return list<T>
     *
     * @template T of AccessConditionHost
     */
    public function visibleOnly(array $hosts): array
    {
        return array_values(array_filter($hosts, fn (AccessConditionHost $host): bool => $this->isVisible($host)));
    }
}
