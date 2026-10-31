<?php

declare(strict_types=1);

namespace App\SeriousGame\Scenario;

/**
 * The scenario of a game of the « scénario » mechanic (design/validated/serious-games-neko-con.md
 * §3.1), as the `scenario` key of its manifest carries it: written by `app:serious-games:sync` from
 * `config/serious_games/{slug}/game.yaml` and `tickets/*.yaml`, read here and nowhere else.
 *
 * **The right answers live here, and nowhere in the game's package.** `version` numbers the
 * scenario: a ticket keeps the version it was started with, and every answer records it.
 *
 * @phpstan-type Act array{n: int, title: string, tools: list<string>}
 */
final readonly class Scenario
{
    /** The step identifier of a ticket whose steps are all answered, waiting to be closed. */
    public const string ALL_STEPS_DONE = '*';

    /**
     * @param list<Act>             $acts
     * @param list<string>          $password       the pieces, in order
     * @param list<ScenarioTicket>  $tickets        in the scenario's order, which is the board's
     * @param array<string, string> $misconceptions misconception => label
     */
    private function __construct(
        private int $version,
        private int $capacity,
        private array $acts,
        private array $password,
        private array $tickets,
        private array $misconceptions,
        private string $helper,
        private bool $restartAnytime,
    ) {
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $acts = [];
        foreach (\is_array($data['acts'] ?? null) ? $data['acts'] : [] as $act) {
            if (\is_array($act) && \is_int($act['n'] ?? null)) {
                $acts[] = [
                    'n' => $act['n'],
                    'title' => \is_string($act['title'] ?? null) ? $act['title'] : '',
                    'tools' => array_values(array_filter(\is_array($act['tools'] ?? null) ? $act['tools'] : [], is_string(...))),
                ];
            }
        }

        $tickets = [];
        foreach (\is_array($data['tickets'] ?? null) ? $data['tickets'] : [] as $ticket) {
            $ticket = \is_array($ticket) ? ScenarioTicket::fromArray($ticket) : null;
            if (null !== $ticket) {
                $tickets[] = $ticket;
            }
        }

        $misconceptions = [];
        foreach (\is_array($data['misconceptions'] ?? null) ? $data['misconceptions'] : [] as $id => $label) {
            if (\is_string($id) && \is_string($label)) {
                $misconceptions[$id] = $label;
            }
        }

        return new self(
            \is_int($data['version'] ?? null) ? $data['version'] : 1,
            \is_int($data['capacity'] ?? null) ? max(1, $data['capacity']) : 4,
            $acts,
            array_values(array_map(static fn (mixed $p): string => \is_scalar($p) ? (string) $p : '', \is_array($data['password'] ?? null) ? $data['password'] : [])),
            $tickets,
            $misconceptions,
            \is_string($data['helper'] ?? null) && '' !== $data['helper'] ? $data['helper'] : 'ines',
            true === ($data['restart_anytime'] ?? null),
        );
    }

    public function version(): int
    {
        return $this->version;
    }

    /** Who answers a wrong step when the step names nobody: Inès at the Neko-Con, Nadia in Black-out. */
    public function helper(): string
    {
        return $this->helper;
    }

    /**
     * Whether « Nouvelle partie » is offered before the end too. The Neko-Con rule (§3.5) waits for
     * the certificate; a game whose manifest says `restart_anytime: true` starts again at any time.
     */
    public function restartAnytime(): bool
    {
        return $this->restartAnytime;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    /** @return list<Act> */
    public function acts(): array
    {
        return $this->acts;
    }

    public function act(int $n): ?array
    {
        foreach ($this->acts as $act) {
            if ($act['n'] === $n) {
                return $act;
            }
        }

        return null;
    }

    /** The password the last ticket asks for: its pieces end to end. */
    public function password(): string
    {
        return implode('', $this->password);
    }

    public function passwordPieces(): int
    {
        return \count($this->password);
    }

    /** @return list<ScenarioTicket> */
    public function tickets(): array
    {
        return $this->tickets;
    }

    /** @return list<string> */
    public function ticketIds(): array
    {
        return array_map(static fn (ScenarioTicket $t): string => $t->id, $this->tickets);
    }

    public function ticket(string $id): ?ScenarioTicket
    {
        foreach ($this->tickets as $ticket) {
            if ($ticket->id === $id) {
                return $ticket;
            }
        }

        return null;
    }

    /** @return list<ScenarioTicket> */
    public function ticketsOfAct(int $act): array
    {
        return array_values(array_filter($this->tickets, static fn (ScenarioTicket $t): bool => $t->act === $act));
    }

    /**
     * How a teacher reads a misconception: its label in the manifest's dictionary, else the
     * shortest option that carries it - a guided question's short wording - else its identifier.
     */
    public function misconceptionLabel(string $id): string
    {
        if (isset($this->misconceptions[$id])) {
            return $this->misconceptions[$id];
        }

        $label = null;
        foreach ($this->tickets as $ticket) {
            foreach ([...$ticket->steps, ...$ticket->guide] as $step) {
                foreach ($step->options as $option) {
                    if ($id === $option['misconception'] && (null === $label || mb_strlen($option['text']) < mb_strlen($label))) {
                        $label = $option['text'];
                    }
                }
            }
        }

        return $label ?? $id;
    }

    /** @return list<string> every misconception a wrong option names, once each */
    public function misconceptions(): array
    {
        $ids = [];
        foreach ($this->tickets as $ticket) {
            foreach ([...$ticket->steps, ...$ticket->guide] as $step) {
                foreach ($step->options as $option) {
                    if (null !== $option['misconception']) {
                        $ids[$option['misconception']] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    /** @return list<string> the courses the tickets revise, in their order of appearance */
    public function courses(): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (ScenarioTicket $t): string => $t->course, $this->tickets))));
    }
}
