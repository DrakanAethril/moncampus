<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ConsoleSessionRepository;
use App\Repository\JobboardOfferRepository;
use App\Repository\LearningPathEnrollmentRepository;
use App\Repository\MobileSessionRepository;
use App\Repository\OAuthAuthorizationCodeRepository;
use App\Repository\OAuthClientRepository;
use App\Repository\OAuthTokenRepository;
use App\Repository\PlatformActivityRepository;
use App\Repository\QuizAttemptEventRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rolling retention of the platform log: beyond 12 months, rows are deleted. One row per login is
 * what makes the table grow - App\Entity\UfaActivity, by contrast, is not purged, its volume is small
 * and its events are the story of a booklet.
 *
 * **A third family since the mode contrôle: the quiz supervision journal, at 12 months.** The entry
 * contract of a supervised évaluation says so in as many words to the student, which is precisely
 * what makes running this command due rather than optional - a retention printed on screen that
 * nothing applies is worse than one never printed.
 *
 * **A second family since the machine console: console sessions, at 90 days.** Their own retention
 * rather than the platform log's, and much shorter, because what they hold is different in kind - a
 * transcript is up to 256 KiB of what was on somebody's screen, and keeping a year of those would be
 * both a volume and a thing to answer for. One command rather than two: it already runs daily, and
 * the question it answers is the same one.
 *
 * **A fourth family since the jobboard: offers older than two years.** An advert published more
 * than twenty-four months ago is not a stale row, it is a job that was filled long ago - and unlike
 * the three families above, deleting it is not tidying, it is the only thing to do with it. Two
 * years rather than one because the board is also read as a history of what a filière's market
 * asked for, and a year of adverts is a single hiring season. Careful not to
 * confuse it with closing: an offer that left its site keeps its row, because how long it stayed
 * online is an information; this is about adverts nobody will ever consult again.
 *
 * **A fifth family since the Claude connector: its expired OAuth secrets, 30 days after expiry.**
 * An expired code or token opens nothing; it is kept a month only so that a replayed refresh token
 * is still recognised - and answered by revoking its connection - rather than met as a stranger.
 * The clients nobody ever consented to go at the same age: registration is open to the internet by
 * specification, and each probe leaves a row. Consented clients and their grants stay, as the trace
 * of who acted through the connector.
 *
 * **A sixth since the mobile refresh tokens: the dead mobile sessions, 30 days after their end**
 * (App\Entity\MobileSession - run out after 30 idle days, or revoked). A dead session opens nothing,
 * and « Mon profil » no longer lists it.
 *
 * **A seventh since the learning paths: a follow-up untouched for 24 months**
 * (App\Entity\LearningPathEnrollment - with the steps it opened and its quiz attempts). It names a
 * person and their scores, and the follow-up screen announces the duration to the author; the path
 * page tells the person their progress is followed. Read on the last activity, not on the start: a
 * path somebody is still working through is not old.
 *
 * To be wired to a scheduled task (once a day is more than enough). With no scheduler, the command
 * stays usable by hand; nothing breaks if it never runs, the tables simply grow.
 */
#[AsCommand(
    name: 'app:purge-platform-activity',
    description: 'Applique les rétentions de la plateforme : journal, sessions de console, surveillance de quiz, offres du jobboard, secrets OAuth expirés, sessions mobiles mortes, suivis de parcours inactifs.',
)]
class PurgePlatformActivityCommand extends Command
{
    private const int DEFAULT_RETENTION_MONTHS = 12;

    /** Console sessions, and their transcripts. Ninety days - see the class docblock. */
    private const int CONSOLE_RETENTION_DAYS = 90;

    /** Jobboard offers, read on the advert's publication date. Twenty-four months. */
    private const int JOBBOARD_RETENTION_MONTHS = 24;

    /** Expired OAuth codes and tokens, and never-consented clients, of the Claude connector. */
    private const int OAUTH_RETENTION_DAYS = 30;

    /** A learning-path follow-up, read on its last activity. Twenty-four months. */
    private const int LEARNING_PATH_RETENTION_MONTHS = 24;

    public function __construct(
        private readonly PlatformActivityRepository $repository,
        private readonly ConsoleSessionRepository $consoleSessions,
        private readonly QuizAttemptEventRepository $quizEvents,
        private readonly JobboardOfferRepository $jobboardOffers,
        private readonly OAuthTokenRepository $oauthTokens,
        private readonly OAuthAuthorizationCodeRepository $oauthCodes,
        private readonly OAuthClientRepository $oauthClients,
        private readonly MobileSessionRepository $mobileSessions,
        private readonly LearningPathEnrollmentRepository $learningPathEnrollments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months', null, InputOption::VALUE_REQUIRED, 'Durée de rétention en mois', self::DEFAULT_RETENTION_MONTHS);
        $this->addOption('console-days', null, InputOption::VALUE_REQUIRED, 'Rétention des sessions de console, en jours', self::CONSOLE_RETENTION_DAYS);
        $this->addOption('jobboard-months', null, InputOption::VALUE_REQUIRED, 'Rétention des offres du jobboard, en mois', self::JOBBOARD_RETENTION_MONTHS);
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte sans supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $months = max(1, (int) $input->getOption('months'));
        $threshold = new \DateTimeImmutable(\sprintf('-%d months', $months));
        $consoleDays = max(1, (int) $input->getOption('console-days'));
        $consoleThreshold = new \DateTimeImmutable(\sprintf('-%d days', $consoleDays));
        $jobboardMonths = max(1, (int) $input->getOption('jobboard-months'));
        $jobboardThreshold = new \DateTimeImmutable(\sprintf('-%d months', $jobboardMonths));
        $oauthThreshold = new \DateTimeImmutable(\sprintf('-%d days', self::OAUTH_RETENTION_DAYS));
        $learningPathThreshold = new \DateTimeImmutable(\sprintf('-%d months', self::LEARNING_PATH_RETENTION_MONTHS));

        if ($input->getOption('dry-run')) {
            $count = (int) $this->repository->createQueryBuilder('a')
                ->select('COUNT(a.id)')
                ->where('a.occurredAt < :threshold')
                ->setParameter('threshold', $threshold)
                ->getQuery()
                ->getSingleScalarResult();

            $io->info(\sprintf('%d entrée(s) antérieure(s) au %s seraient supprimées.', $count, $threshold->format('d/m/Y')));
            $io->info(\sprintf(
                '%d session(s) de console antérieure(s) au %s seraient supprimées.',
                $this->consoleSessions->countOlderThan($consoleThreshold),
                $consoleThreshold->format('d/m/Y'),
            ));
            $io->info(\sprintf(
                '%d événement(s) de surveillance antérieur(s) au %s seraient supprimés.',
                $this->quizEvents->countOlderThan($threshold),
                $threshold->format('d/m/Y'),
            ));
            $io->info(\sprintf(
                '%d offre(s) du jobboard publiée(s) avant le %s seraient supprimées.',
                $this->jobboardOffers->countPublishedBefore($jobboardThreshold),
                $jobboardThreshold->format('d/m/Y'),
            ));
            $io->info(\sprintf(
                '%d jeton(s), %d code(s) et %d client(s) OAuth du connecteur Claude expirés avant le %s seraient supprimés.',
                $this->oauthTokens->countExpiredBefore($oauthThreshold),
                $this->oauthCodes->countExpiredBefore($oauthThreshold),
                $this->oauthClients->countUnconsentedBefore($oauthThreshold),
                $oauthThreshold->format('d/m/Y'),
            ));
            $io->info(\sprintf(
                '%d session(s) mobile(s) expirée(s) ou révoquée(s) avant le %s seraient supprimées.',
                $this->mobileSessions->countDeadBefore($oauthThreshold),
                $oauthThreshold->format('d/m/Y'),
            ));
            $io->info(\sprintf(
                '%d suivi(s) de parcours inactif(s) depuis le %s seraient supprimés.',
                (int) $this->learningPathEnrollments->createQueryBuilder('e')->select('COUNT(e.id)')->where('e.lastActivityAt < :threshold')->setParameter('threshold', $learningPathThreshold)->getQuery()->getSingleScalarResult(),
                $learningPathThreshold->format('d/m/Y'),
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->deleteOlderThan($threshold);
        $io->success(\sprintf('%d entrée(s) antérieure(s) au %s supprimée(s).', $deleted, $threshold->format('d/m/Y')));

        $consoles = $this->consoleSessions->deleteOlderThan($consoleThreshold);
        $io->success(\sprintf('%d session(s) de console antérieure(s) au %s supprimée(s).', $consoles, $consoleThreshold->format('d/m/Y')));

        // The same 12 months as the platform log, and for once the duration is a promise made on
        // screen: the entry contract of a supervised évaluation announces it to the student.
        $quizEvents = $this->quizEvents->deleteOlderThan($threshold);
        $io->success(\sprintf('%d événement(s) de surveillance antérieur(s) au %s supprimé(s).', $quizEvents, $threshold->format('d/m/Y')));

        // Its own duration, and its own reading of what "old" means: the advert's publication date,
        // falling back to the day the offer was first seen when it never carried one.
        $offers = $this->jobboardOffers->deletePublishedBefore($jobboardThreshold);
        $io->success(\sprintf('%d offre(s) du jobboard publiée(s) avant le %s supprimée(s).', $offers, $jobboardThreshold->format('d/m/Y')));

        $tokens = $this->oauthTokens->deleteExpiredBefore($oauthThreshold);
        $codes = $this->oauthCodes->deleteExpiredBefore($oauthThreshold);
        $clients = $this->oauthClients->deleteUnconsentedBefore($oauthThreshold);
        $io->success(\sprintf('%d jeton(s), %d code(s) et %d client(s) OAuth du connecteur Claude supprimé(s).', $tokens, $codes, $clients));

        // The same 30 days as the connector's secrets, read on the day the session died.
        $mobile = $this->mobileSessions->deleteDeadBefore($oauthThreshold);
        $io->success(\sprintf('%d session(s) mobile(s) supprimée(s).', $mobile));

        $followUps = $this->learningPathEnrollments->purgeInactiveSince($learningPathThreshold);
        $io->success(\sprintf('%d suivi(s) de parcours inactif(s) depuis le %s supprimé(s).', $followUps, $learningPathThreshold->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
