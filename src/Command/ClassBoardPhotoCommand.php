<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ClassBoard\ClassBoardPhotoOfTheDay;
use App\Service\ClassBoard\CommonsUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The virtual board's photograph of the day (App\Service\ClassBoard\ClassBoardPhotoOfTheDay).
 *
 * Scheduled every hour: the first pass of the day fetches it, the others find it there and only
 * clean. So a Commons that does not answer at midnight is simply asked again an hour later, and the
 * boards keep yesterday's photograph meanwhile - a **warning**, never a non-zero exit: an outage of
 * Wikimedia must not page anybody every hour.
 */
#[AsCommand(
    name: 'app:class-board:photo',
    description: 'Récupère sur Wikimedia Commons la photo du jour des tableaux virtuels et efface les anciennes.',
)]
class ClassBoardPhotoCommand extends Command
{
    use SharedLockableTrait;

    public function __construct(
        private readonly ClassBoardPhotoOfTheDay $photoOfTheDay,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('replace', null, InputOption::VALUE_NONE, 'Tirer une autre photo pour aujourd’hui, même si elle est déjà là.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock()) {
            $io->comment('Une autre exécution est déjà en cours.');

            return Command::SUCCESS;
        }

        $today = ClassBoardPhotoOfTheDay::today();

        try {
            [$photo, $fetched] = $this->photoOfTheDay->fetch($today, (bool) $input->getOption('replace'));
            $io->writeln(sprintf(
                '%s : %s (%s, %s)',
                $fetched ? 'Photo du jour récupérée' : 'Photo du jour déjà là',
                $photo->getCommonsTitle(),
                $photo->getAuthor(),
                $photo->getLicenseName(),
            ));
        } catch (CommonsUnavailableException $exception) {
            $this->logger->warning('Class board photo of the day not fetched: {reason}', ['reason' => $exception->getMessage()]);
            $io->warning('Photo du jour non récupérée : '.$exception->getMessage());
        }

        $cleaned = $this->photoOfTheDay->clean($today);
        if ($cleaned > 0) {
            $io->writeln(sprintf('%d ancienne(s) photo(s) effacée(s).', $cleaned));
        }

        return Command::SUCCESS;
    }
}
