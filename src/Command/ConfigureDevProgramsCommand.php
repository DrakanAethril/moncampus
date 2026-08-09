<?php

namespace App\Command;

use App\Entity\Modality;
use App\Entity\Program;
use App\Enum\ProgramAlternanceCalendarMode;
use App\Enum\VisibilityLevel;
use App\Repository\ModalityRepository;
use App\Repository\ProgramRepository;
use App\Service\FileUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * OUTIL DE DÉVELOPPEMENT - ouvre toutes les formations d'une base de dev : visibilité, modalités,
 * fonctionnalités, et remplacement du calendrier d'alternance généré par le PDF fourni.
 *
 * À ne pas confondre avec un réglage de production : c'est justement parce que ces cases sont des
 * choix pédagogiques, faits formation par formation dans Paramètres > Pédagogique, qu'on ne les
 * force en masse que sur un poste de développement.
 */
#[AsCommand(
    name: 'app:configure-dev-programs',
    description: '[dev] Rend les formations visibles, active leurs fonctionnalités et téléverse les calendriers d\'alternance.',
)]
class ConfigureDevProgramsCommand extends Command
{
    private const string CALENDAR_DIR = 'design/sources/EDT/planning_alternance';

    /** Même préfixe de stockage que le formulaire de la formation (Settings\ProgramController). */
    private const string CALENDAR_PREFIX = 'programs/alternance-calendar/';

    /** Les deux modalités attendues sur chaque formation. */
    private const array MODALITIES = ['Initiale', 'Alternance'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programRepository,
        private readonly ModalityRepository $modalityRepository,
        private readonly FileUploadService $fileUploadService,
        #[Autowire(param: 'kernel.project_dir')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans les enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $modalities = [];
        foreach (self::MODALITIES as $name) {
            $modality = $this->modalityRepository->findOneBy(['name' => $name]);
            if (!$modality instanceof Modality) {
                $io->error(\sprintf('Modalité « %s » introuvable.', $name));

                return Command::FAILURE;
            }
            $modalities[] = $modality;
        }

        $calendars = $this->findCalendars();
        $rows = [];

        foreach ($this->programRepository->findBy(['inactiveDate' => null], ['id' => 'ASC']) as $program) {
            $program->setVisibility(VisibilityLevel::Everyone);
            $program->setTimetableVisibility(VisibilityLevel::Everyone);
            $program->setSyllabusVisibility(VisibilityLevel::Everyone);
            $program->setAlternanceCalendarVisibility(VisibilityLevel::Everyone);
            $program->setTimetableManagementEnabled(true);
            $program->setFinancialManagementEnabled(true);
            $program->setAssignmentManagementEnabled(true);
            $program->setInternshipManagementEnabled(true);

            foreach ($modalities as $modality) {
                // Modality possède le ManyToMany : c'est par lui que Doctrine voit le lien.
                $modality->addProgram($program);
            }

            $calendar = $calendars[$program->getShortName()] ?? null;
            $status = 'généré (inchangé)';
            if (null !== $calendar) {
                if (!$dryRun) {
                    $old = $program->getAlternanceCalendarFileKey();
                    $key = $this->fileUploadService->upload(
                        self::CALENDAR_PREFIX,
                        \sprintf('%d-%d.pdf', $program->getId(), time()),
                        new UploadedFile($calendar, basename($calendar), 'application/pdf', test: true),
                    );
                    $program->setAlternanceCalendarFileKey($key);
                    $this->entityManager->flush();

                    if (null !== $old) {
                        $this->fileUploadService->delete($old);
                    }
                }
                $program->setAlternanceCalendarMode(ProgramAlternanceCalendarMode::File);
                $status = basename($calendar);
            }

            $rows[] = [$program->getShortName(), 'visible', 'Initiale + Alternance', 'toutes', $status];
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Formation', 'Visibilité', 'Modalités', 'Fonctionnalités', "Calendrier d'alternance"], $rows);

        $message = \sprintf('%d formation(s) ouverte(s), %d calendrier(s) téléversé(s).', \count($rows), \count($calendars));
        $dryRun ? $io->note($message." (essai à blanc : rien n'a été enregistré)") : $io->success($message);

        return Command::SUCCESS;
    }

    /**
     * Les PDF sont nommés « <date>_-_Calendrier_<FORMATION>_-_<année>.pdf » : c'est le nom court de
     * la formation, entre « Calendrier_ » et « _-_ », qui dit à qui chacun appartient.
     *
     * @return array<string, string> nom court de la formation → chemin du PDF
     */
    private function findCalendars(): array
    {
        $calendars = [];
        foreach (glob($this->projectDir.'/'.self::CALENDAR_DIR.'/*.pdf') ?: [] as $path) {
            if (preg_match('/Calendrier_(.+?)_-_/', basename($path), $matches)) {
                $calendars[$matches[1]] = $path;
            }
        }

        return $calendars;
    }
}
