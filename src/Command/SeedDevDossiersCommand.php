<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Dossier;
use App\Entity\DossierDocument;
use App\Entity\DossierGroup;
use App\Entity\DossierReview;
use App\Entity\DossierSubmission;
use App\Entity\User;
use App\Enum\DossierDepositType;
use App\Enum\DossierReviewAction;
use App\Enum\DossierValidationProfile;
use App\Repository\DossierRepository;
use App\Repository\ProgramRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * DEVELOPMENT TOOL — rebuilds the handoff's own data set so a local screen can be put next to its
 * screenshot.
 *
 * « Dossier de soutenance — BTS SIO 2 », its six documents in three groups, and one dépôt per cell
 * of the prototype's ten-by-six matrix, so every one of the seven derived statuses is on screen at
 * once: a validated rapport, one in validation, one whose correction was asked for, a facultative
 * annexe simply deposited, a missing attestation, a late one, and a document not yet visible.
 *
 * **Never run anywhere but a development machine.** It writes dossiers, dépôts and échanges under
 * real students' names, and `--reset` deletes what it wrote before writing it again. The dates are
 * relative to today so the statuses stay what they are meant to be whenever it is run: the
 * prototype's calendar was October 2026, and a fixed one would have every document late by now.
 *
 * The files are *not* uploaded: a dépôt's storage key points at nothing, so « Ouvrir » and
 * « Télécharger » will 404. What is being compared here is the arrangement of the screens, and
 * pushing six hundred kilobytes into the dev bucket to see a pastille is not worth it.
 */
#[AsCommand(
    name: 'app:seed-dev-dossiers',
    description: '[dev] Recrée le jeu de démonstration du handoff « Dossier documentaire ».',
)]
class SeedDevDossiersCommand extends Command
{
    private const string TITLE = 'Dossier de soutenance — BTS SIO 2';

    /**
     * The prototype's six documents: name, group, required, deposit type, visible in N days from
     * today, due in N days, late allowed, validation profile.
     *
     * @var list<array{0: string, 1: string, 2: bool, 3: DossierDepositType, 4: int, 5: int, 6: bool, 7: DossierValidationProfile}>
     */
    private const array DOCUMENTS = [
        ['Rapport de stage', 'Rapport écrit', true, DossierDepositType::Upload, -45, 8, false, DossierValidationProfile::Validation],
        ['Annexes techniques', 'Rapport écrit', false, DossierDepositType::Upload, -45, 8, true, DossierValidationProfile::Deposit],
        ['Lien du dépôt Git', 'Rapport écrit', false, DossierDepositType::Url, -45, 8, false, DossierValidationProfile::Deposit],
        ['Support de présentation', 'Soutenance', true, DossierDepositType::Upload, -10, 29, true, DossierValidationProfile::Validation],
        ['Attestation de stage', 'Soutenance', true, DossierDepositType::Upload, -45, -3, false, DossierValidationProfile::Deposit],
        ['Autorisation de diffusion', '', false, DossierDepositType::Upload, 12, 34, false, DossierValidationProfile::Deposit],
    ];

    /**
     * The prototype's matrix, one row per cible, one letter per document:
     *
     *   c = correction demandée · a = en attente · v = validé · d = déposé · . = rien déposé
     *
     * « rien déposé » reads as « À déposer » or « En retard » depending on the document's own date,
     * and the last column reads « À venir » for everybody, its document not being visible yet -
     * which is why the matrix says nothing about them: a status is derived, never written down.
     */
    private const array MATRIX = [
        'c....',
        'vddav',
        'a.d.v',
        'vddvv',
        '....v',
        'vd.av',
        'addlv',
        'c.d..',
        'vddvv',
        '....v',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DossierRepository $dossiers,
        private readonly ProgramRepository $programs,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('program', null, InputOption::VALUE_REQUIRED, 'Nom court de la formation ciblée', 'SIO2')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Supprime le dossier de démonstration avant de le recréer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $shortName = (string) $input->getOption('program');

        $program = null;

        foreach ($this->programs->findAllActiveWithStudents() as $candidate) {
            if (0 === strcasecmp($candidate->getShortName(), $shortName)) {
                $program = $candidate;
                break;
            }
        }

        if (null === $program) {
            $io->error(\sprintf('Aucune formation active nommée « %s ».', $shortName));

            return Command::FAILURE;
        }

        /** @var list<User> $students */
        $students = array_values($program->getStudents()->toArray());

        if ([] === $students) {
            $io->error(\sprintf('La formation « %s » n\'a aucun étudiant.', $shortName));

            return Command::FAILURE;
        }

        foreach ($this->dossiers->findBy(['title' => self::TITLE]) as $existing) {
            if (!$input->getOption('reset')) {
                $io->warning('Le dossier de démonstration existe déjà. Relancez avec --reset.');

                return Command::SUCCESS;
            }

            $this->entityManager->remove($existing);
        }

        $this->entityManager->flush();

        $validators = $this->pickValidators();

        if ([] === $validators) {
            $io->error('Aucun compte enseignant ou administrateur pour jouer les validateurs.');

            return Command::FAILURE;
        }

        $today = new \DateTimeImmutable('today');
        $dossier = new Dossier();
        $dossier->setCreatedBy($validators[0]);
        $dossier->setTitle(self::TITLE);
        $dossier->setDescription('Déposez les pièces de votre dossier de soutenance. Le rapport et le support sont relus par vos deux tuteurs avant validation.');
        $dossier->setStartsOn($today->modify('-45 days'));
        $dossier->setEndsOn($today->modify('+34 days'));
        $dossier->addTargetProgram($program);

        foreach ($validators as $validator) {
            $dossier->addValidator($validator);
        }

        $groups = [];
        $documents = [];

        foreach (self::DOCUMENTS as [$name, $groupTitle, $required, $type, $visible, $due, $late, $profile]) {
            if ('' !== $groupTitle && !isset($groups[$groupTitle])) {
                $groups[$groupTitle] = new DossierGroup($dossier, $groupTitle, \count($groups));
            }

            $documents[] = (new DossierDocument($dossier))
                ->setName($name)
                ->setGroup('' === $groupTitle ? null : $groups[$groupTitle])
                ->setRequired($required)
                ->setDepositType($type)
                ->setVisibleFrom($today->modify($visible.' days'))
                ->setDueOn($today->modify($due.' days'))
                ->setLateAllowed($late)
                ->setValidationProfile($profile)
            ;
        }

        $dossier->publish();
        $this->entityManager->persist($dossier);

        $deposits = $this->seedMatrix($dossier, $documents, $students, $validators);

        $this->entityManager->flush();

        $io->success(\sprintf(
            '« %s » publié sur %s : %d documents, %d cibles, %d dépôts.',
            self::TITLE,
            $program->getDisplayShortName(),
            \count($documents),
            \count($students),
            $deposits,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<DossierDocument> $documents
     * @param list<User>            $students
     * @param list<User>            $validators
     */
    private function seedMatrix(Dossier $dossier, array $documents, array $students, array $validators): int
    {
        $deposits = 0;

        foreach ($students as $index => $student) {
            $codes = self::MATRIX[$index % \count(self::MATRIX)];

            foreach (str_split($codes) as $column => $code) {
                if ('.' === $code || !isset($documents[$column])) {
                    continue;
                }

                $document = $documents[$column];
                $submittedFor = DossierDepositType::Url === $document->getDepositType()
                    ? DossierSubmission::ofUrl($document, $student, 1, 'https://gitlab.beaupeyrat.test/'.$student->getUsername().'/soutenance')
                    : DossierSubmission::ofFile($document, $student, 1, 'dossier-submissions/seed-'.$document->getId().'-'.$index.'.pdf', $this->filename($document, $student, 1), 2_412_000);

                $this->entityManager->persist($submittedFor);
                ++$deposits;

                if ('c' === $code) {
                    // A correction requested on v1, and no v2 yet: the cell reads « À corriger » and
                    // the cible's screen carries the comment that asked for it.
                    $this->entityManager->persist(new DossierReview(
                        $submittedFor,
                        $validators[1 % \count($validators)],
                        DossierReviewAction::CorrectionRequested,
                        'Les annexes ne sont pas paginées et la partie 3 ne cite pas les sources. À reprendre avant validation.',
                    ));
                }

                if ('v' === $code && $document->needsValidation()) {
                    $this->entityManager->persist(new DossierReview($submittedFor, $validators[0], DossierReviewAction::Validated, null));
                }

                // 'a' and 'd' leave the dépôt unanswered: on a document in validation that reads
                // « En validation », on a plain one « Déposé ». One letter, two readings, and the
                // difference is the document's profile - which is the rule this data set exists to
                // show.
            }
        }

        return $deposits;
    }

    private function filename(DossierDocument $document, User $student, int $version): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $document->getName()) ?? 'document');

        return trim($slug, '-').'-'.$student->getUsername().'-v'.$version.'.pdf';
    }

    /**
     * Three validateurs, taken from the teachers and administrators the local directory holds.
     *
     * @return list<User>
     */
    private function pickValidators(): array
    {
        return \array_slice($this->users->findActiveMatchingAnyRole(['ROLE_TEACHER', 'ROLE_ADMIN', 'ROLE_STAFF']), 0, 3);
    }
}
