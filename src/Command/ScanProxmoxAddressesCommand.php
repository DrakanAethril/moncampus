<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\IpRangeRepository;
use App\Service\Network\IpAllocator;
use App\Service\Network\RangeScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads every declared range back from its hypervisor and reports the gaps.
 *
 * **Without this, a range never empties.** The application does not destroy machines, so it is
 * never in the loop when one is destroyed in Proxmox: the address simply stops being carried and
 * nothing would ever notice. The scan is the only mechanism that sees it disappear - it reports the
 * address as orphaned, and an administrator frees it.
 *
 * It also frees abandoned reservations along the way, which is the other slow leak: a creation
 * wizard somebody walked away from holds its address for ever otherwise.
 *
 * Meant for a cron every few minutes. Note that anything writing to the database in this
 * application needs MERCURE_URL in its environment - ux-turbo publishes on every flush, CLI
 * included, and the failure surfaces at flush time rather than at start-up.
 */
#[AsCommand(
    name: 'app:proxmox:scan-addresses',
    description: 'Relit les adresses réellement portées par les machines et signale les écarts.',
)]
class ScanProxmoxAddressesCommand extends Command
{
    public function __construct(
        private readonly IpRangeRepository $ranges,
        private readonly RangeScanner $scanner,
        private readonly IpAllocator $allocator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('range', null, InputOption::VALUE_REQUIRED, 'Ne balayer qu’une plage, par identifiant');
        $this->addOption('keep-reservations', null, InputOption::VALUE_NONE, 'Ne pas libérer les réservations abandonnées');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $onlyOption = $input->getOption('range');
        $only = is_numeric($onlyOption) ? (int) $onlyOption : null;

        $ranges = $this->ranges->findOrdered();

        if (null !== $only) {
            $ranges = array_values(array_filter($ranges, static fn ($range): bool => $range->getId() === $only));
        }

        if ([] === $ranges) {
            $io->warning('Aucune plage d’adresses active n’est déclarée.');

            return Command::SUCCESS;
        }

        if (true !== $input->getOption('keep-reservations')) {
            $released = $this->allocator->releaseStaleReservations();

            if ($released > 0) {
                $io->text(\sprintf('%d réservation(s) abandonnée(s) libérée(s).', $released));
            }
        }

        $rows = [];
        $failures = 0;
        $gaps = 0;

        foreach ($ranges as $range) {
            $report = $this->scanner->scan($range);

            if (null !== $report->failure) {
                ++$failures;
                $rows[] = [$range->getLabel(), $range->getCidr(), 'KO', '—', '—', $report->failure];
                continue;
            }

            $gaps += \count($report->gaps);

            $rows[] = [
                $range->getLabel(),
                $range->getCidr(),
                'OK',
                \sprintf('%d lues', $report->guestsRead),
                \sprintf('%d / %d libres', $report->freeCount, $report->capacity),
                0 === \count($report->gaps)
                    ? '—'
                    : \sprintf('%d écart(s), dont %d conflit(s)', \count($report->gaps), $report->conflictCount()),
            ];
        }

        $io->table(['Plage', 'Réseau', 'État', 'Machines', 'Occupation', 'Écarts'], $rows);

        if ($failures > 0) {
            $io->error(\sprintf('%d plage(s) sur %d n’ont pas pu être balayées.', $failures, \count($rows)));

            return Command::FAILURE;
        }

        if ($gaps > 0) {
            // Not a failure: gaps are the normal output of a scan, and a cron that alerted on them
            // would alert every time somebody creates a machine by hand.
            $io->warning(\sprintf('%d écart(s) à traiter dans le registre.', $gaps));

            return Command::SUCCESS;
        }

        $io->success(\sprintf('%d plage(s) balayée(s), aucun écart.', \count($rows)));

        return Command::SUCCESS;
    }
}
