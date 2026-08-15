<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Option;
use App\Entity\Program;
use App\Entity\ProgramCertification;
use App\Entity\Skill;
use App\Entity\SkillGroup;
use App\Entity\User;
use App\Enum\CertificationKind;
use App\Referential\BachelorInfoTsfCatalog;
use App\Referential\ReferentialLabelMatcher;
use App\Referential\TsfImportReport;
use App\Repository\ProgramCertificationRepository;
use App\Repository\ProgramRepository;
use App\Repository\SkillGroupRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the Bac+3 Informatique training referential (App\Referential\BachelorInfoTsfCatalog) into
 * ONE Program, named by --program.
 *
 * One program at a time on purpose: production runs several years of this course side by side, and
 * each year owns its own copy of the referential (SkillGroup/Skill hang off a Program). Run it once
 * per year's program id.
 *
 * It is meant to be run in production, so it is built to be boring:
 *
 * - It never deletes and never renames. It fills the referential columns that are still empty and
 *   creates only what is missing outright.
 * - It never overwrites content that is already there. --refresh does, and that is the only flag
 *   that can lose an admin's writing. The rule is per FIELD, not per row: these columns are new, so
 *   an existing competency whose label somebody edited long ago still has an empty
 *   "Description métier" waiting to be filled.
 * - Matching is exact once case, accents and apostrophes are set aside
 *   (App\Referential\ReferentialLabelMatcher). An entry it can place twice is reported and skipped,
 *   never guessed; an entry it cannot place at all is created, and announced so a dry run shows it.
 * - --dry-run writes nothing and prints the same report. That is the intended first run - but note
 *   it never flushes, so it cannot see what the database would refuse: it reported a clean run
 *   right up until the real one hit skill_group.created_by_id being NOT NULL. Read it as "what
 *   would be matched and created", not as "this will succeed".
 *
 * Rows it creates are stamped with --author (a username, default "stharaud"), because
 * SkillGroup/Skill carry AuditableTrait and a command has no logged-in user - same option and same
 * default as the EDT imports.
 *
 * "Intervenant" is resolved from the referential's "F. Sautour" against the program's own teachers,
 * and only when exactly one of them matches on both surname and first-name initial. This
 * referential names three different Sautour; every name it cannot pin down is listed at the end for
 * somebody to assign by hand.
 */
#[AsCommand(
    name: 'app:import-tsf-referential',
    description: 'Charge le référentiel de compétences Bac+3 Informatique (TSF) dans une formation.',
)]
class ImportTsfReferentialCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProgramRepository $programs,
        private readonly SkillGroupRepository $skillGroups,
        private readonly ProgramCertificationRepository $certifications,
        private readonly UserRepository $users,
        private readonly BachelorInfoTsfCatalog $catalog,
        private readonly ReferentialLabelMatcher $matcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('program', null, InputOption::VALUE_REQUIRED, 'Identifiant de la formation à peupler');
        $this->addOption('author', null, InputOption::VALUE_REQUIRED, "Identifiant de l'auteur porté par les lignes créées", 'stharaud');
        $this->addOption('refresh', null, InputOption::VALUE_NONE, 'Réécrit aussi les champs déjà remplis (seule option destructive)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Montre ce qui serait fait, sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $programOption = $input->getOption('program');
        if (!\is_string($programOption) || !ctype_digit($programOption)) {
            $io->error("--program est obligatoire et attend l'identifiant numérique d'une formation.");

            return Command::INVALID;
        }

        $programId = (int) $programOption;
        $program = $this->programs->find($programId);
        if (!$program instanceof Program) {
            $io->error(sprintf("Aucune formation ne porte l'identifiant %d.", $programId));

            return Command::FAILURE;
        }

        // SkillGroup and Skill carry AuditableTrait, whose created_by_id is NOT NULL - a row this
        // command creates has to name somebody, and a CLI run has no logged-in user. Same option and
        // same default as the EDT imports.
        $authorUsername = (string) $input->getOption('author');
        $author = $this->users->findOneBy(['username' => $authorUsername]);
        if (!$author instanceof User) {
            $io->error(sprintf('Aucun utilisateur « %s ».', $authorUsername));

            return Command::FAILURE;
        }

        $refresh = true === $input->getOption('refresh');
        $dryRun = true === $input->getOption('dry-run');

        $io->title(sprintf('Référentiel TSF → %s (#%d)', $program->getName(), $programId));
        if ($dryRun) {
            $io->note('Simulation : rien ne sera écrit.');
        }
        if ($refresh && !$dryRun) {
            $io->warning('--refresh : les champs déjà remplis seront réécrits.');
        }

        $report = new TsfImportReport();
        $options = $this->indexOptions($program);
        $teachers = $this->indexTeachers($program);

        // Read once. Groups created during this run are appended in memory, so a second catalogue
        // entry can never create the same group twice before the flush.
        $groups = $this->skillGroups->findAllActiveForProgram($program);

        foreach ($this->catalog->groups() as $groupPosition => $definition) {
            $group = $this->resolveGroup($program, $definition, $options, $groups, $author, $report, $io);
            if (!$group instanceof SkillGroup) {
                continue;
            }

            // Guarded on the catalogue's own value, not just on the row's: the cross-cutting block
            // has no CCP code, so an unguarded call would rewrite null over null on every run and
            // report a field written on what is otherwise a no-op.
            if (null !== $definition['code']) {
                $this->writeIfEmpty($group->getCode(), $refresh, static function () use ($group, $definition): void {
                    $group->setCode($definition['code']);
                }, $report);
            }

            // The catalogue's own sequence IS the referential's sequence, and nothing in the app
            // lets anybody reorder these yet - so it is written every time rather than only when
            // empty, which is what puts a newly created group in its right place among the others.
            $group->setOrder(($groupPosition + 1) * 10);

            foreach ($definition['skills'] as $skillPosition => $skillDefinition) {
                $skill = $this->resolveSkill($group, $skillDefinition, $author, $report, $io);
                if (!$skill instanceof Skill) {
                    continue;
                }

                $this->fillSkill($skill, $skillDefinition, $skillPosition, $teachers, $refresh, $report);
            }
        }

        $this->importCertifications($program, $options, $refresh, $report, $io);

        if ($dryRun) {
            $this->entityManager->clear();
        } else {
            $this->entityManager->flush();
        }

        $io->section('Bilan');
        $io->listing($report->summary());

        if ([] !== $report->unresolvedTeachers) {
            $io->warning('Intervenants à saisir à la main (aucune correspondance certaine parmi les enseignants de la formation) :');
            $io->listing($report->distinctUnresolvedTeachers());
        }

        if ([] !== $report->problems) {
            // Not a failure: the run did everything it could place, and the list says what it could
            // not. Exiting non-zero would make a scheduled run look broken over a missing option.
            $io->warning('Entrées laissées de côté :');
            $io->listing($report->problems);
        }

        $io->success($dryRun ? 'Simulation terminée, rien écrit.' : 'Import terminé.');

        return Command::SUCCESS;
    }

    /** @return array<string, Option> normalised short name => option */
    private function indexOptions(Program $program): array
    {
        $indexed = [];
        foreach ($program->getOptions() as $option) {
            $indexed[$this->matcher->normalize($option->getShortName())] = $option;
        }

        return $indexed;
    }

    /** @return array<int, User> */
    private function indexTeachers(Program $program): array
    {
        $indexed = [];
        foreach ($program->getTeachers() as $teacher) {
            $id = $teacher->getId();
            if (null !== $id) {
                $indexed[$id] = $teacher;
            }
        }

        return $indexed;
    }

    /**
     * @param array{code: string|null, label: string, aliases?: list<string>, optionShortName: string|null, skills: list<array<string, mixed>>} $definition
     * @param array<string, Option>                                                                                                            $options
     * @param list<SkillGroup>                                                                                                                 $groups     read once, appended to on creation
     */
    private function resolveGroup(Program $program, array $definition, array $options, array &$groups, User $author, TsfImportReport $report, SymfonyStyle $io): ?SkillGroup
    {
        $labels = [];
        foreach ($groups as $index => $candidate) {
            $labels[$index] = $candidate->getLabel();
        }

        $key = $this->matcher->findKey($labels, $definition['label'], $definition['aliases'] ?? []);

        if (null !== $key) {
            ++$report->groupsMatched;
            $io->text(sprintf('= bloc « %s »', $definition['label']));

            return $groups[$key];
        }

        // Nothing matched. Creating is right for a group the referential has and the database does
        // not (the cross-cutting competencies), and wrong if the label merely diverges - which is
        // why the dry run exists and why the creation is announced rather than silent.
        $group = new SkillGroup($definition['label'], $program);

        $optionShortName = $definition['optionShortName'];
        if (null !== $optionShortName) {
            $option = $options[$this->matcher->normalize($optionShortName)] ?? null;
            if (!$option instanceof Option) {
                $report->addProblem(sprintf('Bloc « %s » : la formation ne porte aucune option « %s ».', $definition['label'], $optionShortName));

                return null;
            }
            $group->addOption($option);
        }

        $group->setCreatedBy($author);
        $this->entityManager->persist($group);
        $groups[] = $group;
        ++$report->groupsCreated;
        $io->text(sprintf('+ bloc « %s »', $definition['label']));

        return $group;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolveSkill(SkillGroup $group, array $definition, User $author, TsfImportReport $report, SymfonyStyle $io): ?Skill
    {
        $label = $this->asString($definition['label'] ?? null);
        $code = $this->asString($definition['code'] ?? null);
        if (null === $label) {
            return null;
        }

        /** @var list<string> $aliases */
        $aliases = \is_array($definition['aliases'] ?? null) ? array_values(array_filter($definition['aliases'], '\is_string')) : [];

        $existing = [];
        $labels = [];
        foreach ($group->getSkills() as $candidate) {
            if (null !== $candidate->getInactiveDate()) {
                continue;
            }
            $index = \count($existing);
            $existing[$index] = $candidate;
            $labels[$index] = $candidate->getLabel();
        }

        $key = $this->matcher->findKey($labels, $label, $aliases);

        if (null !== $key) {
            ++$report->skillsMatched;
            $io->text(sprintf('  = %s %s', $code ?? '?', $label));

            return $existing[$key];
        }

        $skill = new Skill($label, $group);
        $skill->setCreatedBy($author);
        $this->entityManager->persist($skill);
        ++$report->skillsCreated;
        $io->text(sprintf('  + %s %s', $code ?? '?', $label));

        return $skill;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, User>     $teachers
     */
    private function fillSkill(Skill $skill, array $definition, int $position, array $teachers, bool $refresh, TsfImportReport $report): void
    {
        $code = $this->asString($definition['code'] ?? null);

        $this->writeIfEmpty($skill->getCode(), $refresh, static function () use ($skill, $code): void {
            $skill->setCode($code);
        }, $report);

        // Same reasoning as the group's order - the catalogue carries the referential's sequence.
        $skill->setOrder(($position + 1) * 10);

        // Written out one by one rather than dispatched through a [getter, setter] table: a table
        // reads shorter but hides the method names from PHPStan, and a typo in one would only
        // surface as a missing field on a printed fiche months later.
        $occupationDescription = $this->asString($definition['occupationDescription'] ?? null);
        $this->writeIfEmpty($skill->getOccupationDescription(), $refresh, static function () use ($skill, $occupationDescription): void {
            $skill->setOccupationDescription($occupationDescription);
        }, $report);

        $knowledge = $this->toList($definition['knowledge'] ?? null);
        $this->writeIfEmpty($skill->getKnowledgeHtml(), $refresh, static function () use ($skill, $knowledge): void {
            $skill->setKnowledgeHtml($knowledge);
        }, $report);

        $activities = $this->toList($definition['activities'] ?? null);
        $this->writeIfEmpty($skill->getActivitiesHtml(), $refresh, static function () use ($skill, $activities): void {
            $skill->setActivitiesHtml($activities);
        }, $report);

        $performanceCriteria = $this->toList($definition['performanceCriteria'] ?? null);
        $this->writeIfEmpty($skill->getPerformanceCriteriaHtml(), $refresh, static function () use ($skill, $performanceCriteria): void {
            $skill->setPerformanceCriteriaHtml($performanceCriteria);
        }, $report);

        $diagnostic = $this->asString($definition['diagnosticAssessment'] ?? null) ?? BachelorInfoTsfCatalog::ASSESSMENT_DIAGNOSTIC;
        $this->writeIfEmpty($skill->getDiagnosticAssessmentHtml(), $refresh, static function () use ($skill, $diagnostic): void {
            $skill->setDiagnosticAssessmentHtml($diagnostic);
        }, $report);

        $summative = $this->asString($definition['summativeAssessment'] ?? null) ?? BachelorInfoTsfCatalog::ASSESSMENT_SUMMATIVE;
        $this->writeIfEmpty($skill->getSummativeAssessmentHtml(), $refresh, static function () use ($skill, $summative): void {
            $skill->setSummativeAssessmentHtml($summative);
        }, $report);

        $certifying = $this->asString($definition['certifyingAssessment'] ?? null) ?? BachelorInfoTsfCatalog::ASSESSMENT_CERTIFYING;
        $this->writeIfEmpty($skill->getCertifyingAssessmentHtml(), $refresh, static function () use ($skill, $certifying): void {
            $skill->setCertifyingAssessmentHtml($certifying);
        }, $report);

        $volumeHours = $this->asString($definition['volumeHours'] ?? null);
        $this->writeIfEmpty($skill->getVolumeHours(), $refresh, static function () use ($skill, $volumeHours): void {
            $skill->setVolumeHours($volumeHours);
        }, $report);

        $teachingPeriod = $this->asString($definition['teachingPeriod'] ?? null);
        $this->writeIfEmpty($skill->getTeachingPeriodLabel(), $refresh, static function () use ($skill, $teachingPeriod): void {
            $skill->setTeachingPeriodLabel($teachingPeriod);
        }, $report);

        $written = $this->asString($definition['teacher'] ?? null);
        if (null === $written || (null !== $skill->getTeacher() && !$refresh)) {
            return;
        }

        $key = $this->matcher->findTeacherKey($this->describeTeachers($teachers), $written);

        if (null === $key) {
            $report->addUnresolvedTeacher($written, $code);

            return;
        }

        $skill->setTeacher($teachers[$key]);
        ++$report->fieldsWritten;
    }

    /**
     * @param array<int, User> $teachers
     *
     * @return array<int, array{firstname: string, lastname: string}>
     */
    private function describeTeachers(array $teachers): array
    {
        $described = [];
        foreach ($teachers as $id => $teacher) {
            $described[$id] = [
                'firstname' => $teacher->getFirstname() ?? '',
                'lastname' => $teacher->getLastname() ?? '',
            ];
        }

        return $described;
    }

    /**
     * @param array<string, Option> $options
     */
    private function importCertifications(Program $program, array $options, bool $refresh, TsfImportReport $report, SymfonyStyle $io): void
    {
        foreach ($this->catalog->certifications() as $definition) {
            $option = $options[$this->matcher->normalize($definition['optionShortName'])] ?? null;
            if (!$option instanceof Option) {
                $report->addProblem(sprintf('Certification « %s » : la formation ne porte aucune option « %s ».', $definition['label'], $definition['optionShortName']));
                continue;
            }

            $certification = $this->certifications->findOneForProgramAndOption($program, $option);

            if ($certification instanceof ProgramCertification && !$refresh) {
                continue;
            }

            if (!$certification instanceof ProgramCertification) {
                $certification = new ProgramCertification($program, $option, $definition['label']);
                $this->entityManager->persist($certification);
                ++$report->certificationsCreated;
                $io->text(sprintf('+ certification « %s » (%s)', $definition['label'], $definition['optionShortName']));
            }

            $certification
                ->setLabel($definition['label'])
                ->setKind(CertificationKind::from($definition['kind']))
                ->setRncpCode($definition['rncpCode'])
                ->setLevel($definition['level'])
                ->setCertifier($definition['certifier']);
        }
    }

    /** Runs $write only when the current value is empty, or when --refresh was asked for. */
    private function writeIfEmpty(?string $current, bool $refresh, callable $write, TsfImportReport $report): void
    {
        if (!$refresh && null !== $current && '' !== trim($current)) {
            return;
        }

        $write();
        ++$report->fieldsWritten;
    }

    /** Renders a fiche's bullet list as the HTML the screens and the export read. */
    private function toList(mixed $items): ?string
    {
        if (!\is_array($items) || [] === $items) {
            return null;
        }

        $rendered = '';
        foreach ($items as $item) {
            if (!\is_string($item)) {
                continue;
            }

            $rendered .= '<li>'.htmlspecialchars($item, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'</li>';
        }

        return '' === $rendered ? null : '<ul>'.$rendered.'</ul>';
    }

    private function asString(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
