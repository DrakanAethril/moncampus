<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\QuizTournamentChallengeRepository;
use App\Repository\QuizTournamentDuelRepository;
use App\Service\QuizTournament\QuizTournamentDuelEngine;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finishes the duels nobody is watching any more (design/validated/quiz-tournament.md §5.2): a duel
 * runs on the server's clock whether its players are there or not, and the duel screen finishes it
 * as soon as one of them asks after its last question - but when both left, nobody asks. This pass
 * brings every unfinished duel up to now through QuizTournamentDuelEngine::settle(), the screens'
 * own door, which finishes those whose last question is over (scores, ratings, exactly once).
 *
 * **Why it has to run**: an unfinished duel refuses « Prêt » to both its players, in every
 * tournament (§4.3, one duel at a time) - a duel left behind would lock them out until somebody
 * finished it.
 *
 * It also writes the challenges whose time is up - a pending challenge past its 24 hours, a room
 * past its own (QuizTournamentChallengeRepository::settleLapsed()): what the screens already read
 * as expired or missed (QuizTournamentChallenge::statusAt()), written down so that a query on the
 * stored status agrees.
 *
 * Scheduled every minute (App\Scheduler\PlatformSchedule). Idempotent and locked
 * (SharedLockableTrait); a duel's own lock keeps it from crossing a player's request.
 */
#[AsCommand(
    name: 'app:quiz-tournament:close-duels',
    description: 'Termine les duels de tournoi dont la dernière question est passée et clôt les défis échus.',
)]
class CloseQuizTournamentDuelsCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly QuizTournamentDuelRepository $duels,
        private readonly QuizTournamentChallengeRepository $challenges,
        private readonly QuizTournamentDuelEngine $engine,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        $now = $this->clock->now();
        $finished = 0;
        $pending = $this->duels->findUnfinished();
        foreach ($pending as $duel) {
            if ($this->engine->settle($duel, $now)) {
                ++$finished;
            }
        }
        $settled = $this->challenges->settleLapsed($now);

        $io->comment(\sprintf('%d duel(s) terminé(s) sur %d en cours ; %d défi(s) ou salle(s) clos.', $finished, \count($pending), $settled));

        return Command::SUCCESS;
    }
}
