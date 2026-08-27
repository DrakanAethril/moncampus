<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Program;
use App\Repository\ProgramRepository;
use App\Service\Game\GameAccess;
use App\Service\Game\GameAliasDrawer;
use App\Service\Game\GamePeriodCloser;
use App\Service\Game\GamePeriodResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Closes every period that has ended, in every formation where the game is running, then opens the
 * next one and draws its aliases (design/validated/gamification.md §8).
 *
 * **Cron, once a day.** A closure is not something a browser tab can be trusted with: it freezes a
 * whole class's period, pays XP that never comes back down and grants rewards, and it has to happen
 * whether or not anybody opened a screen that morning.
 *
 * **Idempotent.** A period already frozen is skipped whole (App\Entity\GamePeriodScore is the guard),
 * and every write inside a closure is either bounded by that snapshot or refused by the ledger's own
 * duplicate check. Running it twice a day is harmless; missing a day only delays a closure.
 *
 * It also attributes the aliases nobody chose within seven days - the same daily pass, because it is
 * the same question: is there anything the calendar has decided since yesterday?
 *
 * > App\Service\Game\GameLedger writes through Doctrine, and `symfony/ux-turbo` pings Mercure on
 * > **every** flush, CLI included. `MERCURE_URL` must be set in the cron environment, and the failure
 * > shows up at flush time rather than at startup. See docs/production.md.
 */
#[AsCommand(
    name: 'app:game:close-period',
    description: 'Clôture les périodes échues du jeu du campus, puis ouvre la suivante et tire les alias.',
)]
class CloseGamePeriodCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private readonly ProgramRepository $programs,
        private readonly GameAccess $access,
        private readonly GamePeriodResolver $periods,
        private readonly GamePeriodCloser $closer,
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

        // InputInterface::getOption() answers mixed by design - narrowed here rather than cast at
        // the point of use, same rule as every other boundary in this application.
        $onlyOption = $input->getOption('program');
        $only = is_numeric($onlyOption) ? (int) $onlyOption : null;
        $dryRun = true === $input->getOption('dry-run');

        if (!$this->access->isFeatureOpenForAnyone()) {
            // The establishment switched the game off. Nothing is closed, nothing is lost: the
            // entries stay, and switching it back on picks the thread up (§9, last row).
            $io->writeln('Le jeu du campus est éteint pour tous les rôles.');
            $this->release();

            return Command::SUCCESS;
        }

        $now = new \DateTimeImmutable();
        $closed = 0;

        foreach ($this->playablePrograms($only) as $program) {
            foreach ($this->periods->periodsOf($program) as $period) {
                $end = $period->getEndDate();

                if (null === $end || $end >= $now) {
                    continue;
                }

                if ($dryRun) {
                    $io->writeln(\sprintf('%s · %s : à clôturer.', $program->getShortName(), $period->getName()));

                    continue;
                }

                $frozen = $this->closer->close($program, $period, $now);

                if ($frozen > 0) {
                    ++$closed;
                    $io->writeln(\sprintf('%s · %s : %d étudiants figés.', $program->getShortName(), $period->getName(), $frozen));
                }
            }
        }

        if (!$dryRun) {
            $attributed = $this->aliases->attributeLapsed($now);

            if ($attributed > 0) {
                $io->writeln(\sprintf('%d pseudonymes attribués par défaut au bout de %d jours.', $attributed, GameAliasDrawer::CHOICE_DAYS));
            }
        }

        $io->success($dryRun ? 'Rien écrit.' : \sprintf('%d période(s) clôturée(s).', $closed));

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
