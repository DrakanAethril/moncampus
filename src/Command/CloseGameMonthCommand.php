<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Program;
use App\Repository\ProgramRepository;
use App\Service\Game\GameAccess;
use App\Service\Game\GameAliasDrawer;
use App\Service\Game\GameMonthCloser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Closes every ranked month that has ended, in every formation where the game is running.
 *
 * **Cron, once a day.** A closure is not something a browser tab can be trusted with: it freezes a
 * whole class's month, pays the first three of it, moves levels that never come back down and grants
 * the frames that go with them. It has to happen whether or not anybody opened a screen that
 * morning, and it must happen exactly once.
 *
 * A month rather than an evaluation period since 2026-08-28: points are counted in the month they
 * were earned in, a month is the same for everybody, and a formation only ranks the ones it ticked.
 *
 * **Idempotent.** A month already frozen is skipped whole - the snapshot is the guard - and every
 * write inside a closure is either bounded by it or refused by the ledger's own duplicate check.
 * Running it twice a day is harmless; missing a day only delays a closure, and the command catches
 * up on any ranked month of the last year that is still open.
 *
 * It also attributes the pseudonyms nobody chose within seven days, in the same pass: it is the same
 * question, asked of the same calendar.
 *
 * > App\Service\Game\GameLedger writes through Doctrine, and `symfony/ux-turbo` pings Mercure on
 * > **every** flush, CLI included. `MERCURE_URL` must be set in the cron environment, and the failure
 * > shows up at flush time rather than at startup. See docs/production.md.
 */
#[AsCommand(
    name: 'app:game:close-month',
    description: 'Clôture les mois échus du jeu du campus, paie le podium et met à jour les niveaux.',
)]
class CloseGameMonthCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly GameAccess $access,
        private readonly GameMonthCloser $closer,
        private readonly GameAliasDrawer $aliases,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('program', null, InputOption::VALUE_REQUIRED, 'Ne traiter que cette formation.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lister ce qui serait clôturé sans rien écrire.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->warning('Une clôture est déjà en cours.');

            return Command::SUCCESS;
        }

        if (!$this->access->isRunningAnywhere()) {
            // No formation is playing. Nothing is closed, nothing is lost: the entries stay, and
            // switching a class back on picks the thread up. Deliberately **not** the role matrix:
            // a silent pilot - the class plays, no role sees it, the administration reads it on the
            // observation screen - is a formation that is playing, and it has to be closed like any
            // other (2026-08-28).
            $io->writeln('Aucune formation ne fait tourner le jeu.');
            $this->release();

            return Command::SUCCESS;
        }

        // InputInterface::getOption() answers mixed by design - narrowed here rather than cast at
        // the point of use, same rule as every other boundary in this application.
        $onlyOption = $input->getOption('program');
        $only = is_numeric($onlyOption) ? (int) $onlyOption : null;
        $dryRun = true === $input->getOption('dry-run');

        $now = new \DateTimeImmutable();
        $closed = 0;

        foreach ($this->playablePrograms($only) as $program) {
            foreach ($this->closer->pendingMonths($program, $now) as $month) {
                if ($dryRun) {
                    $io->writeln(\sprintf('%s · %s : à clôturer.', $program->getShortName(), $month->key()));

                    continue;
                }

                $frozen = $this->closer->close($program, $month, $now);

                if ($frozen > 0) {
                    ++$closed;
                    $io->writeln(\sprintf('%s · %s : %d étudiants figés.', $program->getShortName(), $month->key(), $frozen));
                }
            }
        }

        if (!$dryRun) {
            $attributed = $this->aliases->attributeLapsed($now);

            if ($attributed > 0) {
                $io->writeln(\sprintf('%d pseudonymes attribués par défaut au bout de %d jours.', $attributed, GameAliasDrawer::CHOICE_DAYS));
            }
        }

        $io->success($dryRun ? 'Rien écrit.' : \sprintf('%d mois clôturé(s).', $closed));

        $this->release();

        return Command::SUCCESS;
    }

    /** @return list<Program> */
    private function playablePrograms(?int $only): array
    {
        return array_values(array_filter(
            $this->programs->findAllActiveWithStudents(),
            fn (Program $program): bool => $program->isGameEnabled()
                && (null === $only || $program->getId() === $only),
        ));
    }
}
