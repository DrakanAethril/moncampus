<?php

declare(strict_types=1);

namespace App\Service\Console;

use App\Console\ConsoleSnippetCatalog;
use App\Entity\ConsoleSession;
use App\Entity\User;
use App\Repository\ConsoleSessionRepository;
use App\Repository\ConsoleSnippetRepository;

/**
 * `Ctrl+K`: one field, one list, three sources merged and labelled.
 *
 * The three are **the person's own snippets** (and the ones colleagues have shared), **the platform
 * catalogue**, and **what has already been typed on this very machine**. The order is not
 * decoration: what somebody uses most comes first, then the catalogue, then the history - a palette
 * that ranked alphabetically would be a list, and a list is what people stop opening.
 *
 * Entries are returned with their tokens already filled, because the palette inserts text into a
 * terminal and there is no second step in which anything could fill them.
 *
 * @phpstan-type PaletteEntry array{id: ?int, label: string, command: string, source: string,
 *     author: ?string, uses: ?int}
 */
class ConsolePalette
{
    /** Enough to choose from without scrolling past what one can read. */
    private const int MAX_PER_SOURCE = 12;

    public function __construct(
        private readonly ConsoleSnippetRepository $snippets,
        private readonly ConsoleSessionRepository $sessions,
    ) {
    }

    /**
     * @return array{snippets: list<PaletteEntry>, catalog: list<PaletteEntry>, history: list<PaletteEntry>}
     */
    public function build(User $user, ConsoleSession $session, string $query): array
    {
        $tokens = $this->tokensOf($session, $user);

        return [
            'snippets' => $this->mine($user, $tokens, $query),
            'catalog' => $this->catalog($tokens, $query),
            'history' => $this->history($session, $query),
        ];
    }

    /**
     * @param array<string, string> $tokens
     *
     * @return list<PaletteEntry>
     */
    private function mine(User $user, array $tokens, string $query): array
    {
        $entries = [];

        foreach ($this->snippets->findVisibleTo($user) as $snippet) {
            $command = ConsoleTokens::fill($snippet->getCommand(), $tokens);

            if (!$this->matches($query, $snippet->getLabel(), $command)) {
                continue;
            }

            $owner = $snippet->getOwner();
            $mine = $owner?->getId() === $user->getId();

            $entries[] = [
                'id' => $snippet->getId(),
                'label' => $snippet->getLabel(),
                'command' => $command,
                'source' => 'snippet',
                // Named only when it is somebody else's: « partagé par A. Dubois » is information,
                // « partagé par vous » is noise.
                'author' => $mine ? null : ($owner?->getDisplayName() ?? $owner?->getUsername()),
                'uses' => $snippet->getUseCount(),
            ];
        }

        return \array_slice($entries, 0, self::MAX_PER_SOURCE);
    }

    /**
     * @param array<string, string> $tokens
     *
     * @return list<PaletteEntry>
     */
    private function catalog(array $tokens, string $query): array
    {
        $entries = [];

        foreach (ConsoleSnippetCatalog::all() as $entry) {
            $command = ConsoleTokens::fill($entry['command'], $tokens);

            if ($this->matches($query, $entry['label'], $command)) {
                $entries[] = [
                    'id' => null,
                    'label' => $entry['label'],
                    'command' => $command,
                    'source' => 'catalog',
                    'author' => null,
                    'uses' => null,
                ];
            }
        }

        return \array_slice($entries, 0, self::MAX_PER_SOURCE);
    }

    /** @return list<PaletteEntry> */
    private function history(ConsoleSession $session, string $query): array
    {
        $host = $session->getHost();

        if (null === $host) {
            return [];
        }

        $transcripts = [];

        foreach ($this->sessions->findForMachine($host, $session->getNode(), $session->getVmid()) as $past) {
            $transcript = $past->getTranscript();

            if (null !== $transcript) {
                $transcripts[] = $transcript;
            }
        }

        $entries = [];

        foreach (ConsoleHistory::extract($transcripts) as $command) {
            if ($this->matches($query, '', $command)) {
                $entries[] = [
                    'id' => null,
                    'label' => '',
                    'command' => $command,
                    'source' => 'history',
                    'author' => null,
                    'uses' => null,
                ];
            }
        }

        return \array_slice($entries, 0, self::MAX_PER_SOURCE);
    }

    /** @return array<string, string> */
    private function tokensOf(ConsoleSession $session, User $user): array
    {
        $account = $session->getGuestAccount();

        return [
            'ip' => $session->getIp(),
            'hostname' => $session->getGuestName() ?? '',
            'login' => $session->getUnixUser(),
            'batch' => $account?->getBatch()?->getLabel() ?? '',
            'teacher' => $user->getDisplayName() ?? $user->getUsername(),
        ];
    }

    /** Substring, case- and accent-insensitively enough for a field somebody types two letters into. */
    private function matches(string $query, string $label, string $command): bool
    {
        if ('' === $query) {
            return true;
        }

        $needle = mb_strtolower($query);

        return str_contains(mb_strtolower($label), $needle) || str_contains(mb_strtolower($command), $needle);
    }
}
